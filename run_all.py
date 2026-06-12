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
import urllib.request
from requests.adapters import HTTPAdapter
try:
    from urllib3.util.retry import Retry
except Exception:
    Retry = None

# ========== 設定（ここだけ書き換える） ==========
# --- STEP1: FileMaker ---
UNC_DIR        = r"\\192.168.61.42\marketing\00_マーケティング準備室\集計\02_リスト運用状況\日報"
FM_FILE        = "202606_作業リスト履歴.fmp12"
SCRIPT_NAME    = "media-db"          # 「スクリプト」メニュー内の項目名（完全一致）
SCRIPT_MENU    = "スクリプト"         # メニュー名（英語版FileMakerなら "Scripts"）
SCRIPT_KEY     = "m"                  # メニューを開いた後、この先頭文字で media-db を選ぶ（空ならSCRIPT_NAMEの頭文字）
FM_WINDOW_WAIT = 60                  # FileMakerウィンドウが出るまでの最大待機秒
FM_SETTLE_WAIT = 4                   # ウィンドウ検出後、操作開始までの待機秒（ログイン無しなので短め）
FM_ACCOUNT     = "list"              # 開く時にアカウント/パスワードを聞かれた場合のアカウント名
FM_PASSWORD    = "list"              # 同パスワード（聞かれなければ無視）

# --- STEP3: 取り込み先サイト ---
BASE     = "https://s-benri.heteml.net/atest/media-db"
LOGIN_ID = "bot"           # 「アカウント管理」で作ったログインID
PASSWORD = "bot0923"       # そのパスワード
PROXY      = ""            # 明示プロキシ（最優先）。例 "http://proxy.xxx.co.jp:8080"。空なら下記の自動検出
USE_PAC    = True          # 社内の自動構成スクリプト(PAC)を自動評価して使う（要: pip install pypac）
FORCE_IPV4 = False         # TLSがIPv6経路で切られる場合に True にする（IPv4を強制）
VERIFY_SSL = True          # 社内のSSL検査で証明書エラーになる場合のみ False にする（自己責任）

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
def _desktop_dirs():
    """『本当のデスクトップ』候補を広めに集める（OneDrive既知フォルダ移動/法人OneDriveにも対応）。"""
    import glob
    home = os.path.expanduser("~")
    dirs = []
    # 1) 既知フォルダAPI（OneDriveへ移動済みでも“今のデスクトップ”を返す。最も確実）
    try:
        import ctypes
        from ctypes import wintypes
        class GUID(ctypes.Structure):
            _fields_ = [("d1", wintypes.DWORD), ("d2", wintypes.WORD),
                        ("d3", wintypes.WORD), ("d4", ctypes.c_byte * 8)]
        FOLDERID_Desktop = GUID(0xB4BFCC3A, 0xDB2C, 0x424C,
                                (ctypes.c_byte * 8)(0xB0, 0x29, 0x7F, 0xE9, 0x9A, 0x87, 0xC6, 0x41))
        ptr = ctypes.c_wchar_p()
        if ctypes.windll.shell32.SHGetKnownFolderPath(ctypes.byref(FOLDERID_Desktop), 0, None, ctypes.byref(ptr)) == 0:
            dirs.append(ptr.value)
            ctypes.windll.ole32.CoTaskMemFree(ptr)
    except Exception:
        pass
    # 2) レジストリ（Shell Folders / User Shell Folders）
    for keypath in (r"Software\Microsoft\Windows\CurrentVersion\Explorer\Shell Folders",
                    r"Software\Microsoft\Windows\CurrentVersion\Explorer\User Shell Folders"):
        try:
            import winreg
            with winreg.OpenKey(winreg.HKEY_CURRENT_USER, keypath) as k:
                dirs.append(os.path.expandvars(winreg.QueryValueEx(k, "Desktop")[0]))
        except Exception:
            pass
    # 3) 既定 + OneDrive（個人/法人）の Desktop / デスクトップ
    dirs.append(os.path.join(home, "Desktop"))
    for pat in (os.path.join(home, "OneDrive*", "Desktop"),
                os.path.join(home, "OneDrive*", "デスクトップ")):
        dirs.extend(glob.glob(pat))
    return dirs


def csv_candidates():
    """逆引き.csv のフルパス候補（重複除去）。CSV_PATH指定があればそれ優先。"""
    if CSV_PATH:
        return [CSV_PATH]
    out, seen = [], set()
    for d in _desktop_dirs():
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
# FileMakerのログイン（アカウント/パスワード）ダイアログ対応
# ----------------------------------------------------------------------
def _looks_like_login_dialog(w):
    """そのウィンドウが『アカウント/パスワード』入力ダイアログらしければ Edit一覧を返す。"""
    try:
        cls = w.class_name() or ""
    except Exception:
        cls = ""
    # ターミナル/ブラウザ等の無関係ウィンドウは対象外（誤入力防止）
    if any(x in cls for x in ("Console", "Cascadia", "Chrome", "Mozilla")):
        return None
    try:
        edits = w.descendants(control_type="Edit")
        btns = [(b.window_text() or "") for b in w.descendants(control_type="Button")]
    except Exception:
        return None
    has_ok = any(re.search(r"OK|開く|サインイン|ログイン", b) for b in btns)
    has_cancel = any(re.search(r"キャンセル|Cancel", b) for b in btns)
    # 2つ以上の入力欄＋OK系＋キャンセル系 → ログインダイアログとみなす（メイン画面は該当しない）
    if len(edits) >= 2 and has_ok and has_cancel:
        return edits
    return None


def handle_filemaker_login(timeout=25):
    """開いた直後にアカウント/パスワードを聞かれたら FM_ACCOUNT/FM_PASSWORD を入力する。
       聞かれなければ（自動ログイン等）何もしないで進む。"""
    from pywinauto import Desktop
    from pywinauto.keyboard import send_keys
    log(f"ログイン要求の確認中（最大{timeout}秒。出たら ID/PW に『{FM_ACCOUNT}』を入力）...", 1)
    end = time.time() + timeout
    while time.time() < end:
        main_ready, dlg, edits = False, None, None
        try:
            for w in Desktop(backend="uia").windows():
                try:
                    t = w.window_text() or ""
                except Exception:
                    t = ""
                if t == DB_NAME:
                    main_ready = True       # メイン画面が出た＝ログイン不要だった
                    continue
                e = _looks_like_login_dialog(w)
                if e is not None:
                    dlg, edits = w, e
                    break
        except Exception:
            pass

        if dlg is not None:
            log("ログイン要求を検出 → ID/パスワードを入力します", 2)
            try:
                dlg.set_focus()
                time.sleep(0.2)
                try:
                    edits[0].set_focus()   # 1つ目（アカウント欄）へ
                except Exception:
                    pass
                time.sleep(0.2)
                # アカウント → Tab → パスワード → Enter（各欄は念のため全選択して上書き）
                send_keys("^a{BACKSPACE}" + FM_ACCOUNT + "{TAB}^a{BACKSPACE}" + FM_PASSWORD + "{ENTER}")
                log(f"✓ アカウント/パスワードに『{FM_ACCOUNT}』を入力して送信しました", 2)
            except Exception as ex:
                log(f"× 自動入力に失敗（{type(ex).__name__}）。手動で {FM_ACCOUNT}/{FM_PASSWORD} を入力してください。", 2)
            time.sleep(2)
            return True

        if main_ready:
            log("ログイン要求なし（認証不要 or 自動ログイン）。そのまま進みます。", 2)
            return False
        time.sleep(1)
    log("ログイン要求は検出されませんでした。そのまま進みます。", 2)
    return False


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
        from pywinauto import Application
        from pywinauto.keyboard import send_keys
    except ImportError:
        raise SystemExit("❌ pywinauto が必要です。 python -m pip install pywinauto を実行してください。")

    # 1) ファイルを開く
    log("FileMakerでファイルを開いています...", 1)
    os.startfile(FM_PATH)

    # 1.5) アカウント/パスワードを聞かれたら入力（聞かれなければスキップ）
    handle_filemaker_login()

    # 2) FileMakerウィンドウの出現を待つ（win32優先＝メニューを名前で直接実行できる）
    log(f"FileMakerウィンドウの出現を待機中（最大{FM_WINDOW_WAIT}秒）...", 1)
    app = None
    backend = "win32"
    deadline = time.time() + FM_WINDOW_WAIT
    while time.time() < deadline and app is None:
        for be in ("win32", "uia"):
            try:
                app = Application(backend=be).connect(
                    title_re=r".*(FileMaker|" + re.escape(DB_NAME) + r").*", timeout=1)
                backend = be
                break
            except Exception:
                app = None
        if app is None:
            time.sleep(1)
    if app is None:
        raise SystemExit("❌ FileMakerのウィンドウが見つかりませんでした。起動を確認してください。")
    try:
        win = app.window(title=DB_NAME)
        win.wait("exists", timeout=15)
    except Exception:
        win = app.top_window()
    log(f"✓ ウィンドウ検出: 「{win.window_text()}」（backend={backend}）", 1)

    # 3) ファイルが開ききるのを少し待つ
    log(f"操作開始まで {FM_SETTLE_WAIT}秒 待機...", 1)
    time.sleep(FM_SETTLE_WAIT)

    # 4) 「スクリプト(S)」→ media-db を実行
    key = (SCRIPT_KEY or SCRIPT_NAME[0])
    log(f"『{SCRIPT_MENU}(S)』メニュー →『{SCRIPT_NAME}』を実行します...", 1)
    ran = False

    # 方法A（最優先）: 標準メニューを“名前で直接”実行（フォーカス不要・最も確実）
    if backend == "win32":
        for mp in (f"{SCRIPT_MENU}->{SCRIPT_NAME}", f"{SCRIPT_MENU}(S)->{SCRIPT_NAME}"):
            try:
                win.menu_select(mp)
                ran = True
                log(f"✓ メニューから実行しました（menu_select: {mp}）", 2)
                break
            except Exception as e:
                log(f"× menu_select 失敗（{mp} / {type(e).__name__}）", 2)

    # 方法B: キーボード（ウィンドウを前面化 → Alt+S → 先頭文字 → Enter）
    if not ran:
        try:
            win.set_focus()
            time.sleep(0.8)
            log(f"キーボード操作: Alt+S → {key} → Enter を送信", 2)
            send_keys("%s")
            time.sleep(1.0)
            send_keys(key)
            time.sleep(0.5)
            send_keys("{ENTER}")
            ran = True
            log("✓ キーボードで実行しました", 2)
        except Exception as e:
            log(f"× キーボード操作も失敗（{type(e).__name__}）", 2)

    if not ran:
        raise SystemExit(
            f"❌ 『{SCRIPT_NAME}』を実行できませんでした。メニュー名（{SCRIPT_MENU}）や"
            f"スクリプト名が実画面と一致しているか確認してください（英語版なら Scripts）。"
        )
    log(f"✓ 『{SCRIPT_NAME}』を実行しました", 1)
    log("（CSVが出ない場合は SCRIPT_NAME と実際のメニュー表示名の一致を確認）", 2)


# ----------------------------------------------------------------------
# STEP2: 取り込むCSVを確定（media-dbが上書きする前提。既存があればそれを使う）
# ----------------------------------------------------------------------
def wait_for_csv(timeout=CSV_WAIT_TIMEOUT, settle=6):
    """取り込む 逆引き.csv を確定して返す。
       ・既存のCSVがあれば（最新のものを）取り込む。media-dbが必ず上書きするため
         更新時刻の新旧は問わない。
       ・半分だけ書き込まれたファイルを読まないよう、サイズの安定だけ確認する。
       ・CSVが1つも無い場合のみ、出現するまで待つ。"""
    banner("STEP2: 取り込むCSVを確定")
    cands = csv_candidates()
    for p in cands:
        if os.path.exists(p):
            when = dt.datetime.fromtimestamp(os.path.getmtime(p)).strftime("%H:%M:%S")
            log(f"候補: {p}（あり {when} / {os.path.getsize(p):,}B）", 1)
        else:
            log(f"候補: {p}（なし）", 1)

    # media-db の書き出し完了を少しだけ待つ（上書き途中を読まないため）
    log(f"書き出し完了を待機（{settle}秒）...", 1)
    time.sleep(settle)

    start = time.time()
    last_size, stable = {}, {}
    while time.time() - start < timeout:
        existing = [p for p in cands if os.path.exists(p)]
        if existing:
            target = max(existing, key=os.path.getmtime)   # 既存の最新CSV
            size = os.path.getsize(target)
            if size > 0 and last_size.get(target) == size:
                stable[target] = stable.get(target, 0) + 1
                log(f"{target} = {size:,}B（安定 {stable[target]}/2）", 2)
                if stable[target] >= 2:
                    log(f"✓ 取り込むCSVを確定: {target}（{size:,} bytes）", 1)
                    return target
            else:
                last_size[target], stable[target] = size, 0
                log(f"{target} = {size:,}B（サイズ確認中）", 2)
        else:
            log("CSVがまだ存在しません。出現を待機中...", 2)
        time.sleep(2)

    # タイムアウト時も、存在すれば最新を返す
    existing = [p for p in cands if os.path.exists(p)]
    return max(existing, key=os.path.getmtime) if existing else None


# ----------------------------------------------------------------------
# STEP3: CSVをサイトへ取り込み
# ----------------------------------------------------------------------
def _autoconfig_url():
    """WinINETの自動構成スクリプト(PAC)URLをレジストリから取得（無ければ空）。"""
    try:
        import winreg
        with winreg.OpenKey(winreg.HKEY_CURRENT_USER,
                            r"Software\Microsoft\Windows\CurrentVersion\Internet Settings") as k:
            return str(winreg.QueryValueEx(k, "AutoConfigURL")[0] or "")
    except Exception:
        return ""


def make_session():
    """(session, 接続方法の説明) を返す。プロキシ/PAC・IPv4強制・証明書検証・リトライを設定。"""
    if FORCE_IPV4:
        try:
            import socket
            import urllib3.util.connection as uc
            uc.allowed_gai_family = lambda: socket.AF_INET
        except Exception:
            pass

    pac_used = False
    if PROXY:
        s = requests.Session()
        s.proxies.update({"http": PROXY, "https": PROXY})
        s.trust_env = False
        method = f"明示PROXY {PROXY}"
    elif USE_PAC:
        try:
            from pypac import PACSession, get_pac
            url = _autoconfig_url()
            try:
                pac = get_pac(url=url) if url else get_pac()
            except Exception:
                pac = None
            s = PACSession(pac=pac) if pac else PACSession()
            pac_used = True
            method = "PAC自動(pypac)" + (f": {url}" if url else ": WPAD探索")
        except ImportError:
            s = requests.Session()
            method = "直結（⚠ pypac 未導入）"
            log("⚠ pypac が未導入のため PAC を使えません（社内環境では接続に失敗します）。", 1)
            log("   PowerShellで  python -m pip install pypac  を実行してから再実行してください。", 1)
    else:
        s = requests.Session()
        method = "直結/システム設定(trust_env)"

    s.headers.update({"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) mediadb-import/1.0"})
    s.verify = VERIFY_SSL
    if not VERIFY_SSL:
        try:
            import urllib3
            urllib3.disable_warnings()
        except Exception:
            pass
    # PACSessionは独自にプロキシを解決するため、リトライアダプタは通常セッションのみ
    if Retry is not None and not pac_used:
        retry = Retry(total=3, connect=3, read=3, backoff_factor=1,
                      status_forcelist=[502, 503, 504])
        adapter = HTTPAdapter(max_retries=retry)
        s.mount("https://", adapter)
        s.mount("http://", adapter)
    return s, method


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

    s, csrf = connect_and_login()

    log("現在データ取得: GET /api.php", 1)
    data = s.get(f"{BASE}/api.php", timeout=15).json()
    media, clients = data.get("media", []), data.get("clients", [])
    before = len(clients)
    log(f"✓ 既存: 顧客 {before}件 / 媒体 {len(media)}件", 2)

    # [4/5] CSV解析 → 顧客化
    log(f"CSV解析: {os.path.basename(path)}", 1)
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
    log(f"保存: POST /api.php（顧客 {len(clients)}件・媒体 {len(media)}件）", 1)
    payload = {"users": data.get("users", []), "media": media, "clients": clients,
               "excludeDomains": data.get("excludeDomains", []), "csrf": csrf}
    r = s.post(f"{BASE}/api.php", json=payload, headers={"X-CSRF-Token": csrf}, timeout=30)
    r.raise_for_status()
    if r.json().get("status") != "success":
        raise SystemExit(f"❌ 保存失敗: {r.json()}")
    log("✓ 保存成功", 2)
    log(f"🎉 完了！ 顧客 {before}→{len(clients)}件（+{added}） / 媒体 {len(media)}件", 0)


# ----------------------------------------------------------------------
# 認証 & 他媒体検索（dashboard / flag-search 共通の「未取得をまとめて検索」）
# ----------------------------------------------------------------------
def connect_and_login():
    """セッションを作りログインして (session, csrf) を返す。"""
    s, method = make_session()
    log(f"接続方法: {method} / IPv4強制={FORCE_IPV4} / 証明書検証={VERIFY_SSL}", 1)
    pac_url = _autoconfig_url()
    if pac_url:
        log(f"PAC(自動構成)URL: {pac_url}", 2)
    log("ログイン画面を取得: GET /index.php", 1)
    r = s.get(f"{BASE}/index.php", timeout=15); r.raise_for_status()
    m = re.search(r'name="csrf"\s+value="([^"]+)"', r.text)
    if not m:
        raise SystemExit("❌ CSRFトークンが取得できません。BASE を確認してください。")
    csrf = m.group(1)
    log(f"✓ CSRF取得（{csrf[:10]}…）", 2)
    log(f"ログイン: POST /index.php（loginId={LOGIN_ID}）", 1)
    r = s.post(f"{BASE}/index.php",
               data={"csrf": csrf, "loginId": LOGIN_ID, "password": PASSWORD}, timeout=15)
    r.raise_for_status()
    if "action=logout" not in r.text:
        raise SystemExit("❌ ログイン失敗。LOGIN_ID/PASSWORD とアカウント管理の内容を確認してください。")
    log("✓ ログイン成功", 2)
    return s, csrf


def search_all_pending(auto_yes=False, limit=None):
    """未取得（searchedAt が無い）顧客を search.php で1件ずつ検索する。
       limit を指定するとその件数だけ実行（テスト用にAPI課金を抑える）。
       これ1回で dashboard / flag-search 両方のデータが揃う（リスト元の個別選択は不要）。"""
    banner("他媒体検索: 未取得をまとめて検索（dashboard / flag-search 共通）")
    s, csrf = connect_and_login()

    log("現在データ取得: GET /api.php", 1)
    data = s.get(f"{BASE}/api.php", timeout=30).json()
    clients = data.get("clients", [])
    pending = [c for c in clients if not c.get("searchedAt")]
    log(f"顧客 {len(clients)}件 / 未取得 {len(pending)}件", 2)
    if not pending:
        log("未取得の顧客はありません。検索対象なしで終了します。", 0)
        return

    # テスト用に件数を絞る（API課金を抑える）
    if limit is not None and 0 < limit < len(pending):
        pending = pending[:limit]
        log(f"★テストモード: 先頭 {limit} 件だけ検索します（残りの未取得分は未実行のまま）", 1)

    # 課金が出るので確認（--yes で省略可）
    if not auto_yes:
        log("⚠ OpenAIのWeb検索を未取得件数ぶん実行します（API利用料が発生します）。", 1)
        try:
            ans = input(f"    {len(pending)}件を検索しますか？ [y/N]: ").strip().lower()
        except EOFError:
            ans = ""
        if ans not in ("y", "yes"):
            log("中止しました（確認なしで実行するには末尾に --yes を付けてください）。", 0)
            return

    ok = fail = total = 0
    t0 = time.time()
    for i, c in enumerate(pending, 1):
        name = c.get("name", "(無名)")
        log(f"[{i}/{len(pending)}] {name} を検索中...", 1)
        st = time.time()
        try:
            r = s.post(f"{BASE}/search.php", json={"id": c.get("id"), "csrf": csrf},
                       headers={"X-CSRF-Token": csrf}, timeout=150)
            if r.status_code == 403:
                raise SystemExit("❌ 検索権限がありません。アカウント管理で bot の権限を"
                                 "『管理者』または『API利用可』に変更してください。")
            r.raise_for_status()
            res = r.json()
            if res.get("status") == "success":
                cnt = int(res.get("count", 0))
                total += cnt
                ok += 1
                extra = f" / 0件（AI応答: {str(res['note'])[:40]}…）" if cnt == 0 and res.get("note") else ""
                log(f"✓ {cnt}件 取得（{time.time()-st:.0f}秒）{extra}", 2)
            else:
                fail += 1
                log(f"× 失敗: {res.get('message', res)}", 2)
        except SystemExit:
            raise
        except Exception as e:
            fail += 1
            log(f"× エラー: {type(e).__name__}: {e}", 2)

    log(f"🎉 検索完了！ 成功 {ok}件 / 失敗 {fail}件 / 取得URL合計 {total}件 / 所要 {time.time()-t0:.0f}秒", 0)
    log("結果は dashboard.php と flag-search.php の両方に反映済みです", 1)
    log("（flag-search はリスト元を選ぶだけで表示。1つずつ検索する必要はありません）", 1)


# ----------------------------------------------------------------------
# オーケストレーション
# ----------------------------------------------------------------------
def main():
    args = sys.argv[1:]
    flags = {"--yes", "--test", "--limit"}
    mode = next((a for a in args if a.startswith("--") and a not in flags), "")
    auto_yes = "--yes" in args

    # 検索の件数制限（テスト用）: --test=1件、--limit N=N件
    limit = None
    if "--test" in args:
        limit = 1
    if "--limit" in args:
        try:
            limit = int(args[args.index("--limit") + 1])
        except (IndexError, ValueError):
            raise SystemExit("❌ --limit の後ろに件数を指定してください（例: --search --limit 3）")

    label = {"--import-only": "取り込みのみ", "--fm-only": "FileMakerのみ",
             "--search": "未取得をまとめて検索"}.get(mode, "全工程（FileMaker→取り込み）")
    banner("作業リスト履歴 → 逆引きDB パイプライン")
    log(f"モード      : {label}" + (f"（テスト: {limit}件）" if (mode == '--search' and limit) else ""), 1)
    log(f"取り込み先  : {BASE}", 1)

    # 他媒体検索（dashboard / flag-search 共通）— ログインだけで完結
    if mode == "--search":
        search_all_pending(auto_yes=auto_yes, limit=limit)
        return

    log(f"FileMaker   : {FM_FILE}", 1)
    log(f"スクリプト  : {SCRIPT_MENU} ▶ {SCRIPT_NAME}", 1)
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

    # 全工程（FileMaker → CSV確定 → 取り込み）
    run_filemaker()
    path = wait_for_csv()          # 既存があればそれを取り込む（media-dbが上書きする前提）
    if not path:
        raise SystemExit(
            "❌ 逆引き.csv が見つかりませんでした。\n"
            "   ・media-db がデスクトップに 逆引き.csv を書き出しているか\n"
            "   ・上の『CSV候補』に保存先が含まれているか\n"
            "   を確認してください。--import-only で手動CSVだけ取り込むことも可能です。"
        )
    import_csv(path)


if __name__ == "__main__":
    try:
        main()
    except requests.exceptions.RequestException as e:
        log(f"❌ 通信エラー: {type(e).__name__}", 0)
        log(f"{e}", 1)
        log("サイトにHTTPSで接続できませんでした。社内ネットワークのプロキシ/ファイアウォール", 1)
        log("経由が必要な可能性が高いです（ブラウザでは開けてもPython直結は遮断される構成）。", 1)
        log(f"Pythonが認識中のプロキシ: {urllib.request.getproxies() or 'なし'}", 1)
        log(f"対処: ①ブラウザで {BASE}/index.php が開くか確認", 1)
        log("      ②『netsh winhttp show proxy』でプロキシを調べ、設定の PROXY に指定して再実行", 1)
        sys.exit(1)
    except Exception as e:
        log(f"❌ エラー: {type(e).__name__}: {e}", 0)
        sys.exit(1)
