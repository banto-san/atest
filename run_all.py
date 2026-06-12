"""
作業リスト履歴(FileMaker) → 逆引きDB(サイト) 一括取り込みパイプライン
============================================================================
ワンクリックで以下を順に実行する:
  STEP1  共有上の .fmp12 を開き、fmp:// でスクリプト『media-db』を実行
         （= デスクトップに 逆引き.csv が書き出される想定）
  STEP2  逆引き.csv が「新しく書き出される（更新される）」のを待つ
  STEP3  サイトに ID/パスワードでログインし、register.php と同じ規則で取り込み

進捗は時刻つきで1ステップずつコンソールに表示します。

使い方:
  python run_all.py              # 全工程（FileMaker → 待機 → 取り込み）
  python run_all.py --import-only   # FileMakerを飛ばし、今あるCSVを取り込むだけ
  python run_all.py --fm-only       # FileMakerで media-db 実行まで（取り込みなし）

必要ライブラリ:
  python -m pip install requests
"""
import os
import sys
import csv
import io
import re
import time
import random
import datetime as dt
from urllib.parse import quote

import requests

# ========== 設定（ここだけ書き換える） ==========
# --- STEP1: FileMaker ---
UNC_DIR      = r"\\192.168.61.42\marketing\00_マーケティング準備室\集計\02_リスト運用状況\日報"
FM_FILE      = "202606_作業リスト履歴.fmp12"
SCRIPT_NAME  = "media-db"
FM_OPEN_WAIT = 15          # ファイルが開ききるまでの待機秒（遅い/重い場合は増やす）

# --- STEP3: 取り込み先サイト ---
BASE     = "https://s-benri.heteml.net/atest/media-db"
LOGIN_ID = "bot"           # 「アカウント管理」で作ったログインID
PASSWORD = "bot0923"       # そのパスワード

# --- CSV（media-db が書き出す先） ---
CSV_PATH         = r"C:\Users\smn0226\Desktop\逆引き.csv"
CSV_WAIT_TIMEOUT = 180     # CSVが更新されるのを待つ最大秒
# ================================================

FM_PATH   = os.path.join(UNC_DIR, FM_FILE)
DB_NAME   = os.path.splitext(FM_FILE)[0]   # 拡張子を除いた名前 = fmp:// で使うDB名
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
# STEP1: FileMakerを開いて media-db を実行
# ----------------------------------------------------------------------
def run_filemaker():
    banner("STEP1: FileMaker を開いて media-db を実行")
    log(f"共有への接続を確認中: {UNC_DIR}", 1)
    if not os.path.exists(FM_PATH):
        raise SystemExit(
            f"❌ FileMakerファイルにアクセスできません:\n   {FM_PATH}\n"
            f"   共有(\\\\192.168.61.42\\marketing)に接続できているか、"
            f"一度エクスプローラーで開いて認証が通るか確認してください。"
        )
    size_kb = os.path.getsize(FM_PATH) / 1024
    log(f"✓ ファイルを確認: {FM_FILE}（{size_kb:,.0f} KB）", 1)

    log("FileMakerでファイルを開いています...", 1)
    os.startfile(FM_PATH)

    for remaining in range(FM_OPEN_WAIT, 0, -1):
        if remaining % 5 == 0 or remaining <= 3:
            log(f"起動待機中... 残り {remaining} 秒（ログイン画面が出たらログインを）", 2)
        time.sleep(1)

    url = f"fmp://$/{quote(DB_NAME)}?script={quote(SCRIPT_NAME)}"
    log(f"fmp:// URL を送信します:", 1)
    log(url, 2)
    os.startfile(url)
    log(f"✓ スクリプト『{SCRIPT_NAME}』の実行リクエストを送信しました", 1)


# ----------------------------------------------------------------------
# STEP2: CSVが新しく書き出されるのを待つ
# ----------------------------------------------------------------------
def wait_for_fresh_csv(baseline_mtime):
    """CSVが baseline より新しく更新され、サイズが安定する（書き込み完了）まで待つ。"""
    banner("STEP2: 逆引き.csv の書き出しを待機")
    log(f"監視ファイル: {CSV_PATH}", 1)
    base_str = dt.datetime.fromtimestamp(baseline_mtime).strftime("%H:%M:%S") if baseline_mtime else "なし(新規作成を待つ)"
    log(f"基準（これより新しくなるのを待つ）: {base_str}", 1)
    log(f"タイムアウト: {CSV_WAIT_TIMEOUT}秒 / 2秒ごとに確認", 1)

    start = time.time()
    last_size, stable = -1, 0
    while time.time() - start < CSV_WAIT_TIMEOUT:
        time.sleep(2)
        elapsed = int(time.time() - start)
        if not os.path.exists(CSV_PATH):
            log(f"経過 {elapsed:3d}秒 / ファイルなし（書き出し前）", 2)
            continue
        if os.path.getmtime(CSV_PATH) <= baseline_mtime:
            log(f"経過 {elapsed:3d}秒 / まだ更新なし（古いまま）", 2)
            continue
        size = os.path.getsize(CSV_PATH)
        if size > 0 and size == last_size:
            stable += 1
            log(f"経過 {elapsed:3d}秒 / サイズ {size:,} bytes（安定 {stable}/2）", 2)
            if stable >= 2:                   # 2回連続でサイズ変化なし=書き込み完了
                log(f"✓ CSVの更新を確認（最終サイズ {size:,} bytes）", 1)
                return True
        else:
            log(f"経過 {elapsed:3d}秒 / サイズ {size:,} bytes（書き込み中）", 2)
            stable, last_size = 0, size
    return False


# ----------------------------------------------------------------------
# STEP3: CSVをサイトへ取り込み
# ----------------------------------------------------------------------
def read_csv_rows(path):
    """UTF-8(BOM可)→ダメならShift_JISで読み、(行×列, 使用エンコーディング) を返す。"""
    with open(path, "rb") as f:
        raw = f.read()
    used = "utf-8(置換)"
    text = None
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


def import_csv():
    banner("STEP3: サイトへ取り込み")
    if not os.path.exists(CSV_PATH):
        raise SystemExit(f"❌ CSVが見つかりません: {CSV_PATH}")

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
    log(f"[4/5] CSV解析: {os.path.basename(CSV_PATH)}", 1)
    rows, enc = read_csv_rows(CSV_PATH)
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
    log(f"スクリプト  : {SCRIPT_NAME}", 1)
    log(f"CSV         : {CSV_PATH}", 1)
    log(f"取り込み先  : {BASE}", 1)

    if mode == "--import-only":
        import_csv(); return
    if mode == "--fm-only":
        run_filemaker()
        log("✓ STEP1のみ完了（取り込みは行いません）", 0)
        return

    # 全工程
    baseline = os.path.getmtime(CSV_PATH) if os.path.exists(CSV_PATH) else 0.0
    run_filemaker()
    if not wait_for_fresh_csv(baseline):
        raise SystemExit(
            "❌ 制限時間内に 逆引き.csv が更新されませんでした。\n"
            "   ・media-db が実行されたか（fmurl 拡張アクセス権が有効か）\n"
            f"   ・書き出し先が {CSV_PATH} になっているか\n"
            "   ・FileMakerの起動が遅い場合は FM_OPEN_WAIT を増やす\n"
            "   を確認してください。--import-only で手動CSVだけ取り込むことも可能です。"
        )
    import_csv()


if __name__ == "__main__":
    try:
        main()
    except Exception as e:
        log(f"❌ エラー: {type(e).__name__}: {e}", 0)
        sys.exit(1)
