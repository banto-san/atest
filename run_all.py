"""
作業リスト履歴(FileMaker) → 逆引きDB(サイト) 一括取り込みパイプライン
============================================================================
ワンクリックで以下を順に実行する:
  STEP1  共有上の .fmp12 を開き、FileMakerの「スクリプト」メニューから『media-db』を実行
         （media-db は デスクトップに 逆引き.csv を Shift-JIS で書き出すスクリプト）
  STEP2  逆引き.csv が「新しく書き出される（更新される）」のを待つ
         ※CSVは実行中PCのデスクトップを自動検出（パス固定不要）
  STEP3  サイトに ID/パスワードでログインし、register.php と同じ規則で取り込み

使い方:
  python run_all.py              # 全工程（FileMaker → 待機 → 取り込み）
  python run_all.py --import-only   # FileMakerを飛ばし、今あるCSVを取り込むだけ
  python run_all.py --fm-only       # FileMakerで media-db 実行まで（取り込みなし）

必要ライブラリ:
  python -m pip install requests pywinauto
"""
import os
import sys
import csv
import io
import re
import time
import random
import datetime as dt

import requests

# ========== 設定（ここだけ書き換える） ==========
# --- STEP1: FileMaker ---
UNC_DIR        = r"\\192.168.61.42\marketing\00_マーケティング準備室\集計\02_リスト運用状況\日報"
FM_FILE        = "202606_作業リスト履歴.fmp12"
SCRIPT_NAME    = "media-db"          # 「スクリプト」メニュー内の項目名（完全一致）
SCRIPT_MENU    = "スクリプト"         # メニュー名（英語版FileMakerなら "Scripts"）
FM_WINDOW_WAIT = 60                  # FileMakerウィンドウが出るまでの最大待機秒
FM_SETTLE_WAIT = 4                   # ウィンドウ検出後、操作開始までの待機秒（ログイン無しなので短め）

# --- STEP3: 取り込み先サイト ---
BASE     = "https://s-benri.heteml.net/atest/media-db"
LOGIN_ID = "bot"           # 「アカウント管理」で作ったログインID
PASSWORD = "bot0923"       # そのパスワード

# --- CSV（media-db が書き出す先） ---
CSV_NAME         = "逆引き.csv"
CSV_PATH         = ""      # 空なら実行中PCのデスクトップを自動検出。固定したい時だけフルパスを書く。
CSV_WAIT_TIMEOUT = 180     # CSVが更新されるのを待つ最大秒
# ================================================

FM_PATH   = os.path.join(UNC_DIR, FM_FILE)
DB_NAME   = os.path.splitext(FM_FILE)[0]
HEADER_RE = re.compile(r"NyoiBow|シリアル|顧客名|会社名|作業日|リストカテゴリー", re.I)


# ----------------------------------------------------------------------
# 進捗ログ（時刻つき・即時表示）
# ----------------------------------------------------------------------
def log(msg, indent=0):
    ts = dt.datetime.now().strftime("%H:%M:%S")
    print(f"[{ts}] {'   ' * indent}{msg}", flush=True)


def banner(title):
    log("")
    log("=" * 54)
    log(f" {title}")
    log("=" * 54)


# ----------------------------------------------------------------------
# CSVの場所（実行中PCのデスクトップ）を自動検出
# ----------------------------------------------------------------------
def csv_candidates():
    """逆引き.csv のフルパス候補を返す。CSV_PATH指定があればそれ優先。"""
    if CSV_PATH:
        return [CSV_PATH]
    dirs = []
    # レジストリの実デスクトップ（OneDriveリダイレクトにも対応）
    try:
        import winreg
        with winreg.OpenKey(winreg.HKEY_CURRENT_USER,
                            r"Software\Microsoft\Windows\CurrentVersion\Explorer\Shell Folders") as k:
            dirs.append(os.path.expandvars(winreg.QueryValueEx(k, "Desktop")[0]))
    except Exception:
        pass
    home = os.path.expanduser("~")
    dirs += [os.path.join(home, "Desktop"), os.path.join(home, "OneDrive", "Desktop")]
    # 重複除去してフルパス化
    out, seen = [], set()
    for d in dirs:
        if not d:
            continue
        key = os.path.normcase(os.path.abspath(d))
        if key not in seen:
            seen.add(key)
            out.append(os.path.join(d, CSV_NAME))
    return out


def pick_existing_csv():
    existing = [p for p in csv_candidates() if os.path.exists(p)]
    if not existing:
        raise SystemExit("❌ 逆引き.csv が見つかりません。候補:\n   " + "\n   ".join(csv_candidates()))
    return max(existing, key=os.path.getmtime)   # 一番新しいものを採用


# ----------------------------------------------------------------------
# STEP1: FileMakerを開いて「スクリプト」メニューから media-db を実行
# ----------------------------------------------------------------------
def run_filemaker():
    banner("STEP1: FileMaker を開いて media-db を実行（メニュー操作）")

    log(f"共有への接続を確認中: {UNC_DIR}", 1)
    if not os.path.exists(FM_PATH):
        raise SystemExit(
            f"❌ FileMakerファイルにアクセスできません:\n   {FM_PATH}\n"
            f"   共有(\\\\192.168.61.42\\marketing)に接続できているか確認してください。"
        )
    log(f"✓ ファイルを確認: {FM_FILE}（{os.path.getsize(FM_PATH)/1024:,.0f} KB）", 1)

    try:
        from pywinauto import Application, Desktop
        from pywinauto.keyboard import send_keys
    except ImportError:
        raise SystemExit("❌ pywinauto が必要です。 python -m pip install pywinauto を実行してください。")

    # 1) ファイルを開く
    log("FileMakerでファイルを開いています...", 1)
    os.startfile(FM_PATH)

    # 2) FileMakerウィンドウの出現を待つ
    log(f"FileMakerウィンドウの出現を待機中（最大{FM_WINDOW_WAIT}秒）...", 1)
    app = None
    deadline = time.time() + FM_WINDOW_WAIT
    while time.time() < deadline:
        try:
            app = Application(backend="uia").connect(
                title_re=r".*(FileMaker|" + re.escape(DB_NAME) + r").*", timeout=1)
            break
        except Exception:
            time.sleep(1)
    if app is None:
        raise SystemExit("❌ FileMakerのウィンドウが見つかりませんでした。起動を確認してください。")

    # メインのデータウィンドウ（タイトル＝ファイル名）を取得
    try:
        win = app.window(title=DB_NAME)
        win.wait("exists", timeout=10)
    except Exception:
        win = app.top_window()
    log(f"✓ ウィンドウ検出: 「{win.window_text()}」", 1)

    # 3) ファイルが開ききるのを少しだけ待つ（ログイン無し）
    log(f"操作開始まで {FM_SETTLE_WAIT}秒 待機...", 1)
    time.sleep(FM_SETTLE_WAIT)

    # 4) 「スクリプト」メニュー → media-db を実行（UIA → ダメならキーボード）
    log(f"『{SCRIPT_MENU}』メニューから『{SCRIPT_NAME}』を実行します...", 1)
    ran = False

    # 方法A: UIA でメニュー項目を「名前で」クリック
    try:
        win.set_focus()
        time.sleep(0.4)
        log("方法A: メニュー項目を名前でクリック（UIA）...", 2)
        win.child_window(title_re=r"\s*" + re.escape(SCRIPT_MENU) + r".*",
                         control_type="MenuItem").click_input()
        time.sleep(0.8)
        item = Desktop(backend="uia").window(title=SCRIPT_NAME, control_type="MenuItem")
        item.wait("visible", timeout=5)
        item.click_input()
        ran = True
        log("✓ メニューからクリックしました（UIA）", 2)
    except Exception as e:
        log(f"× UIAでのクリック不可（{type(e).__name__}）→ キーボードに切替", 2)
        try:
            send_keys("{ESC}{ESC}")
        except Exception:
            pass

    # 方法B: キーボード（Alt+S でメニュー → 先頭文字 'm' → Enter）
    if not ran:
        try:
            win.set_focus()
            time.sleep(0.4)
            log("方法B: キーボード操作（Alt+S → m → Enter）...", 2)
            send_keys("%s")               # Alt+S = スクリプトメニュー
            time.sleep(0.8)
            send_keys(SCRIPT_NAME[0])     # 'm' で media-db へ（メニュー内で m始まりは media-db のみ）
            time.sleep(0.3)
            send_keys("{ENTER}")
            ran = True
            log("✓ キーボードで実行しました", 2)
        except Exception as e:
            log(f"× キーボード操作も失敗（{type(e).__name__}）", 2)

    if not ran:
        raise SystemExit(
            f"❌ media-db をメニューから実行できませんでした。\n"
            f"   『{SCRIPT_MENU}』メニューに『{SCRIPT_NAME}』が出ているか確認してください。"
        )
    log(f"✓ 『{SCRIPT_NAME}』を実行しました", 1)


# ----------------------------------------------------------------------
# STEP2: CSVが新しく書き出されるのを待つ（複数のデスクトップ候補を監視）
# ----------------------------------------------------------------------
def wait_for_fresh_csv(baselines):
    """baselines = {path: mtime}。新しく更新され、サイズが安定した path を返す（無ければ None）。"""
    banner("STEP2: 逆引き.csv の書き出しを待機")
    for p, b in baselines.items():
        when = dt.datetime.fromtimestamp(b).strftime("%H:%M:%S") if b else "無し"
        log(f"監視: {p}（既存: {when}）", 1)
    log(f"タイムアウト: {CSV_WAIT_TIMEOUT}秒 / 2秒ごとに確認", 1)

    start = time.time()
    sizes = {p: -1 for p in baselines}
    stables = {p: 0 for p in baselines}
    while time.time() - start < CSV_WAIT_TIMEOUT:
        time.sleep(2)
        elapsed = int(time.time() - start)
        moved = False
        for p, base in baselines.items():
            if not os.path.exists(p) or os.path.getmtime(p) <= base:
                continue
            size = os.path.getsize(p)
            moved = True
            if size > 0 and size == sizes[p]:
                stables[p] += 1
                log(f"経過 {elapsed:3d}秒 / {p} = {size:,}B（安定 {stables[p]}/2）", 2)
                if stables[p] >= 2:
                    log(f"✓ CSVの更新を確認: {p}（{size:,} bytes）", 1)
                    return p
            else:
                sizes[p], stables[p] = size, 0
                log(f"経過 {elapsed:3d}秒 / {p} = {size:,}B（書き込み中）", 2)
        if not moved:
            log(f"経過 {elapsed:3d}秒 / まだ更新なし", 2)
    return None


# ----------------------------------------------------------------------
# STEP3: CSVをサイトへ取り込み
# ----------------------------------------------------------------------
def read_csv_rows(path):
    """UTF-8(BOM可)→ダメならShift_JISで読み、(行×列, 使用エンコーディング) を返す。"""
    with open(path, "rb") as f:
        raw = f.read()
    used, text = "utf-8(置換)", None
    for enc in ("utf-8-sig", "cp932"):
        try:
            text = raw.decode(enc); used = enc; break
        except UnicodeDecodeError:
            continue
    if text is None:
        text = raw.decode("utf-8", errors="replace")
    rows = csv.reader(io.StringIO(text))
    cleaned = [[c.strip() for c in r] for r in rows if any(c.strip() for c in r)]
    return cleaned, used


def clean_media_name(s):
    return re.sub(r"[\s　]*申込日[：:].*$", "", s or "").strip()


def previous_business_day():
    """前営業日（土日を飛ばす）。※祝日はテストでは簡略化のため未対応。"""
    d = dt.date.today()
    while True:
        d -= dt.timedelta(days=1)
        if d.weekday() < 5:
            return d.isoformat()


def gen_id(prefix):
    return f"{prefix}{int(time.time()*1000)}{random.randint(0,99999)}"


def find_or_create_media(media, name, created_log):
    n = (name or "").strip()
    if not n:
        return ""
    for m in media:
        if str(m.get("name", "")).lower() == n.lower():
            return m["id"]
    mid = gen_id("m")
    media.append({"id": mid, "name": n, "domain": "-"})
    created_log.append(n)
    return mid


def import_csv(path):
    banner("STEP3: サイトへ取り込み")
    log(f"取り込むCSV: {path}", 1)

    s = requests.Session()
    s.headers.update({"User-Agent": "mediadb-import-test/1.0"})

    # [1/5] ログイン画面で CSRF とセッションCookieを取得
    log("[1/5] ログイン画面を取得: GET /index.php", 1)
    r = s.get(f"{BASE}/index.php", timeout=15); r.raise_for_status()
    m = re.search(r'name="csrf"\s+value="([^"]+)"', r.text)
    if not m:
        raise SystemExit("❌ CSRFトークンが取得できません。BASE を確認してください。")
    csrf = m.group(1)
    log(f"✓ CSRFトークン取得（{csrf[:10]}…）/ Cookie取得", 2)

    # [2/5] ID/パスワードでログイン
    log(f"[2/5] ログイン: POST /index.php（loginId={LOGIN_ID}）", 1)
    r = s.post(f"{BASE}/index.php",
               data={"csrf": csrf, "loginId": LOGIN_ID, "password": PASSWORD}, timeout=15)
    r.raise_for_status()
    if "action=logout" not in r.text:
        raise SystemExit("❌ ログイン失敗。LOGIN_ID/PASSWORD とアカウント管理の内容を確認してください。")
    log("✓ ログイン成功", 2)

    # [3/5] 現在のデータ取得
    log("[3/5] 現在データ取得: GET /api.php", 1)
    data = s.get(f"{BASE}/api.php", timeout=15).json()
    media, clients = data.get("media", []), data.get("clients", [])
    before = len(clients)
    log(f"✓ 既存: 顧客 {before}件 / 媒体 {len(media)}件", 2)

    # [4/5] CSV解析 → 顧客化
    log(f"[4/5] CSV解析: {os.path.basename(path)}", 1)
    rows, enc = read_csv_rows(path)
    log(f"✓ エンコーディング: {enc} / {len(rows)}行 読み込み", 2)

    order_date = previous_business_day()
    log(f"受注日にセットする前営業日: {order_date}", 2)
    new_media, added, skipped = [], 0, 0
    for idx, cols in enumerate(rows):
        cols = cols + [""] * (6 - len(cols))
        if idx == 0 and HEADER_RE.search(",".join(cols)):
            log("- 1行目は見出し行 → スキップ", 2)
            continue
        name = cols[2].strip()
        if not name:
            skipped += 1
            log(f"- {idx+1}行目: 顧客名が空 → スキップ", 2)
            continue
        src = clean_media_name(cols[5])
        sid = find_or_create_media(media, src, new_media) if src else ""
        clients.append({
            "id": gen_id("c"), "serial": cols[0].strip(), "name": name,
            "industry": cols[4].strip(), "orderDate": order_date,
            "address": cols[3].strip(), "sourceMediaId": sid, "usedMediaIds": [],
        })
        added += 1
        log(f"+ {name}" + (f"（媒体: {src}）" if src else "（媒体なし）"), 2)

    if new_media:
        log(f"※ 新規に作成した媒体 {len(new_media)}件: {', '.join(new_media)}", 2)
    log(f"✓ 追加 {added}件 / スキップ {skipped}件", 2)
    if added == 0:
        raise SystemExit("❌ 有効なデータがありません。CSVの列順を確認してください。")

    # [5/5] 保存
    log(f"[5/5] 保存: POST /api.php（顧客 {len(clients)}件・媒体 {len(media)}件）", 1)
    payload = {"users": data.get("users", []), "media": media, "clients": clients,
               "excludeDomains": data.get("excludeDomains", []), "csrf": csrf}
    r = s.post(f"{BASE}/api.php", json=payload, headers={"X-CSRF-Token": csrf}, timeout=30)
    r.raise_for_status()
    if r.json().get("status") != "success":
        raise SystemExit(f"❌ 保存失敗: {r.json()}")
    log("✓ 保存成功", 2)
    log(f"🎉 完了！ 顧客 {before}→{len(clients)}件（+{added}） / 媒体 {len(media)}件", 0)


# ----------------------------------------------------------------------
# オーケストレーション
# ----------------------------------------------------------------------
def main():
    mode = sys.argv[1] if len(sys.argv) > 1 else ""

    banner("作業リスト履歴 → 逆引きDB 取り込みパイプライン")
    log(f"モード      : {'取り込みのみ' if mode=='--import-only' else 'FileMakerのみ' if mode=='--fm-only' else '全工程'}", 1)
    log(f"FileMaker   : {FM_FILE}", 1)
    log(f"スクリプト  : {SCRIPT_MENU} ▶ {SCRIPT_NAME}", 1)
    log(f"取り込み先  : {BASE}", 1)
    cands = csv_candidates()
    log("CSV候補（自動検出）:", 1)
    for p in cands:
        log(p, 2)

    if mode == "--import-only":
        import_csv(pick_existing_csv()); return
    if mode == "--fm-only":
        run_filemaker()
        log("✓ STEP1のみ完了（取り込みは行いません）", 0)
        return

    # 全工程
    baselines = {p: (os.path.getmtime(p) if os.path.exists(p) else 0.0) for p in cands}
    run_filemaker()
    path = wait_for_fresh_csv(baselines)
    if not path:
        raise SystemExit(
            "❌ 制限時間内に 逆引き.csv が更新されませんでした。\n"
            "   ・media-db がCSVを書き出したか（デスクトップに 逆引き.csv ができるか）\n"
            "   ・上の『CSV候補』に書き出し先が含まれているか\n"
            "   を確認してください。--import-only で手動CSVだけ取り込むことも可能です。"
        )
    import_csv(path)


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        log(f"❌ エラー: {type(e).__name__}: {e}", 0)
        sys.exit(1)
