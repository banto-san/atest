import os
import time
import csv
import urllib.request
import urllib.parse
import gspread
import traceback
import re
import ssl
import requests
import urllib3
from selenium import webdriver
from selenium.webdriver.chrome.service import Service
from webdriver_manager.chrome import ChromeDriverManager
from selenium.webdriver.common.by import By
from selenium.webdriver.support.ui import WebDriverWait
from selenium.webdriver.support import expected_conditions as EC

# ==========================================
# 🚨 セキュリティ突破設定
# ==========================================
os.environ['CURL_CA_BUNDLE'] = ''
os.environ['REQUESTS_CA_BUNDLE'] = ''
os.environ['PYTHONHTTPSVERIFY'] = '0'
os.environ['WDM_SSL_VERIFY'] = '0'

try:
    ssl._create_default_https_context = ssl._create_unverified_context
except AttributeError:
    pass

proxies = urllib.request.getproxies()
if proxies:
    os.environ['HTTP_PROXY'] = proxies.get('http', '')
    os.environ['HTTPS_PROXY'] = proxies.get('https', '')

urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

old_request = requests.Session.request
def new_request(self, method, url, **kwargs):
    kwargs['verify'] = False
    return old_request(self, method, url, **kwargs)
requests.Session.request = new_request

# ==========================================
# 🚨 設定エリア
# ==========================================
INPUT_CSV_FILE = 'input_data.csv'
JSON_FILE_NAME = 's-benri-sstask-9214ab746b96.json'
SPREADSHEET_ID = '1bh7hSMQvkB_xrHu4vqDzqSb5zB7V58nXBzQDmltXOYQ'

# 💡 シートを2つに分けます！
SHEET_FOUND = 'GBPあり（一致）'
SHEET_NOT_FOUND = 'GBPなし・不一致'

# 💡 各シートの見出し（データの列順と必ず一致させること！）
HEADER_FOUND = [
    "検索した名前", "元の住所", "検索用住所", "【GBP】会社名", "【GBP】業種",
    "【GBP】住所", "【GBP】電話番号", "【GBP】ウェブサイト", "【GBP】マップURL",
    "【検索結果】1ページ目URL", "チェック日時"
]
HEADER_NOT_FOUND = [
    "検索した名前", "元の住所", "検索用住所", "判定結果（理由）",
    "電話番号印", "発見した電話番号（代表）", "番号が見つかったサイト（社名・住所一致のみ）",
    "【検索結果】1ページ目URL", "チェック日時"
]

# 💡 電話番号探索の設定
PHONE_FETCH_TIMEOUT = 10   # requestsで1サイトを読み込む最大秒数
PHONE_FETCH_WAIT = 1       # サイト間の待機秒数（アクセス過多防止）
USE_SELENIUM_FALLBACK = True   # requestsで取れなかったらSeleniumで開き直すか

# ==========================================
# 💡 日本の電話番号パターン
# ==========================================
# 0X-XXXX-XXXX 系（市外局番の桁数いろいろ） / フリーダイヤル / 携帯
PHONE_PATTERN = re.compile(
    r'0(?:\d-\d{4}|\d{2}-\d{3}|\d{3}-\d{2}|\d{4}-\d)-\d{4}'   # 固定電話
    r'|0120-?\d{2,3}-?\d{3,4}'                                # フリーダイヤル
    r'|0(?:7|8|9)0-\d{4}-\d{4}'                               # 携帯
)

# ==========================================
# 🛠 共通関数
# ==========================================
def init_driver():
    options = webdriver.ChromeOptions()
    options.add_argument('--ignore-certificate-errors')
    options.add_argument('--start-maximized')
    options.add_argument('--disable-blink-features=AutomationControlled')

    if proxies and 'http' in proxies:
        proxy_url = proxies['http'].replace('http://', '').replace('https://', '')
        options.add_argument(f'--proxy-server={proxy_url}')

    service = Service(ChromeDriverManager().install())
    driver = webdriver.Chrome(service=service, options=options)
    return driver

# 💡 シートの1行目（見出し）が正しい並びになっているか確認して、違えば直す
#    既存シートが古い見出しのままでも、これで毎回そろえます。
def ensure_header(ws, header):
    try:
        current = ws.row_values(1)
    except Exception:
        current = []
    if current[:len(header)] == header:
        return
    try:
        ws.update(range_name="A1", values=[header], value_input_option="USER_ENTERED")
    except TypeError:
        # 古いgspread（引数の順番が違う）向けのフォールバック
        ws.update("A1", [header], value_input_option="USER_ENTERED")
    print(f"   🧹 シート『{ws.title}』の見出しを最新の並びにそろえました。")

# 💡 会社名を比較するために、余計な文字（株式会社やスペース）を削る魔法の関数
def clean_company_name(name):
    if not name: return ""
    remove_words = ["株式会社", "有限会社", "合同会社", "一般社団法人", "財団法人", "医療法人", " ", "　", "・", "（", "）", "(", ")"]
    res = name
    for w in remove_words:
        res = res.replace(w, "")
    return res.lower()

# 💡 HTMLから「見える文字」だけを取り出す（script/styleは除去）
def html_to_text(html):
    body = re.sub(r'<(script|style)[^>]*>.*?</\1>', ' ', html, flags=re.IGNORECASE | re.DOTALL)
    text = re.sub(r'<[^>]+>', ' ', body)
    return text

# 💡 ページの中身に住所が載っているか確認する（番地より前の「都道府県＋市区町村＋町名」で照合）
def page_has_address(text, clean_address):
    if not clean_address:
        return False
    norm_text = re.sub(r'\s', '', text)
    norm_addr = re.sub(r'\s', '', clean_address)
    return bool(norm_addr) and norm_addr in norm_text

# 💡 1つのサイトを開いて「会社名＋住所の一致確認」と「電話番号探索」を行う関数
#    戻り値は dict。 name_matched と address_matched の両方が True かつ phone があるときだけ信用してよい。
def inspect_site(url, org_name, clean_address, driver=None):
    result = {
        "url": url, "fetched": False, "phone": "", "how": "",
        "name_matched": False, "address_matched": False, "page_title": "",
    }

    html = ""
    # --- ① まず requests で軽く取得（速い） ---
    try:
        headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"}
        r = requests.get(url, headers=headers, timeout=PHONE_FETCH_TIMEOUT)
        r.encoding = r.apparent_encoding  # 文字化け対策
        html = r.text
    except Exception:
        html = ""

    # --- ② requestsで取れなければ Selenium で開き直す（保険） ---
    if not html and driver is not None and USE_SELENIUM_FALLBACK:
        try:
            driver.get(url)
            time.sleep(3)
            html = driver.page_source
        except Exception:
            html = ""

    if not html:
        return result
    result["fetched"] = True

    # --- ページタイトル（社名確認＆記録用） ---
    mt = re.search(r'<title[^>]*>(.*?)</title>', html, re.IGNORECASE | re.DOTALL)
    result["page_title"] = re.sub(r'\s+', ' ', mt.group(1)).strip() if mt else ""

    # --- 見える文字を取り出す ---
    text = html_to_text(html)

    # --- 🚨 会社名の一致確認（タイトル優先、なければ本文） ---
    #     CSVの顧客名（株式会社などを除いた中核）が、ページ側に出てくるかを確認する。
    clean_org = clean_company_name(org_name)
    if clean_org:
        if clean_org in clean_company_name(result["page_title"]) or clean_org in clean_company_name(text):
            result["name_matched"] = True

    # --- 🚨 住所の一致確認（社名だけだと同名他社の恐れがあるので住所でも裏取り） ---
    result["address_matched"] = page_has_address(text, clean_address)

    # --- 電話番号の抽出（tel:リンク最優先 → 本文の正規表現） ---
    tel_links = re.findall(r'href=["\']tel:([+\d\-\(\)\s]+)["\']', html, re.IGNORECASE)
    if tel_links:
        result["phone"] = re.sub(r'[^\d+]', '', tel_links[0])
        result["how"] = "tel:リンク"
    else:
        m = PHONE_PATTERN.search(text)
        if m:
            result["phone"] = m.group()
            result["how"] = "本文テキスト"

    return result

# ==========================================
# 🚀 メイン処理
# ==========================================
print("🚀 Google検索ロボット（高精度モード＋電話番号探索）を起動します...")

driver = None
try:
    print("📊 スプレッドシートに接続中...")
    gc = gspread.service_account(filename=JSON_FILE_NAME)
    sh = gc.open_by_key(SPREADSHEET_ID)

    # --- GBPありシートの準備 ---
    try: ws_found = sh.worksheet(SHEET_FOUND)
    except: ws_found = sh.add_worksheet(title=SHEET_FOUND, rows="1000", cols="12")
    ensure_header(ws_found, HEADER_FOUND)

    # --- GBPなしシートの準備（見出しも毎回そろえる） ---
    try: ws_not_found = sh.worksheet(SHEET_NOT_FOUND)
    except: ws_not_found = sh.add_worksheet(title=SHEET_NOT_FOUND, rows="1000", cols="10")
    ensure_header(ws_not_found, HEADER_NOT_FOUND)

    print("✅ スプレッドシート接続完了！")

    search_targets = []
    if not os.path.exists(INPUT_CSV_FILE):
        print(f"❌ {INPUT_CSV_FILE} が見つかりません。")
        exit()

    try:
        with open(INPUT_CSV_FILE, mode='r', encoding='utf-8-sig') as f:
            reader = csv.reader(f)
            header = next(reader, None)
            for row in reader:
                if len(row) >= 2: search_targets.append({"name": row[0], "address": row[1]})
    except UnicodeDecodeError:
        with open(INPUT_CSV_FILE, mode='r', encoding='cp932') as f:
            reader = csv.reader(f)
            header = next(reader, None)
            for row in reader:
                if len(row) >= 2: search_targets.append({"name": row[0], "address": row[1]})

    print(f"📁 CSVから {len(search_targets)} 件のデータを読み込みました！")

    if len(search_targets) > 0:
        driver = init_driver()
        wait = WebDriverWait(driver, 10)

        for idx, target in enumerate(search_targets):
            org_name = target['name']
            full_address = target['address']

            clean_address = re.sub(r'[0-9０-９]+(丁目|番|号|番地|-|ー|‐).*', '', full_address)
            search_query = f"{org_name} {clean_address}"

            print(f"\n[{idx + 1}/{len(search_targets)}] 🔍 検索中: {search_query}")

            safe_query = urllib.parse.quote(search_query)
            driver.get(f"https://www.google.com/search?q={safe_query}")
            time.sleep(5)

            gbp_name = ""
            gbp_category = ""
            gbp_address = ""
            gbp_phone = ""
            gbp_website = ""
            gbp_map_url = ""
            search_urls = []
            urls_str = ""

            # ==========================================
            # 💡 GBP（ナレッジパネル）の抽出とURL取得
            # ==========================================
            try:
                kp_box = driver.find_elements(By.ID, "rhs")
                if len(kp_box) > 0:
                    print("🎯 画面右側にGBPらしき枠を発見しました！")

                    try: gbp_name = driver.find_element(By.XPATH, "//div[@id='rhs']//h2[@data-attrid='title']").text
                    except: pass

                    try: gbp_category = driver.find_element(By.XPATH, "//div[@id='rhs']//span[contains(@class, 'YhemCb')]").text
                    except: pass

                    try: gbp_address = driver.find_element(By.XPATH, "//div[@id='rhs']//span[contains(text(), '所在地') or contains(text(), '住所')]/following-sibling::span").text
                    except: pass

                    try:
                        gbp_phone = driver.find_element(By.XPATH, "//div[@id='rhs']//span[contains(text(), '電話番号')]/following-sibling::span//span").text
                    except:
                        try: gbp_phone = driver.find_element(By.XPATH, "//div[@id='rhs']//span[contains(@aria-label, '電話番号')]").text
                        except: pass

                    try:
                        website_btn = driver.find_element(By.XPATH, "//div[@id='rhs']//a[contains(text(), 'ウェブサイト') or contains(., 'ウェブサイト')]")
                        gbp_website = website_btn.get_attribute("href")
                    except: pass

                    # 💡 GoogleマップのURL取得
                    try:
                        map_links = driver.find_elements(By.XPATH, "//div[@id='rhs']//a[contains(@href, '/maps/')]")
                        for link in map_links:
                            href = link.get_attribute("href")
                            if href:
                                gbp_map_url = href
                                break
                    except: pass

                else:
                    print("⚠️ GBP（ナレッジパネル）は表示されませんでした。")
            except Exception as e:
                print(f"⚠️ GBP抽出中にエラー: {e}")

            # ==========================================
            # 💡 検索結果（1ページ目）のURL抽出
            # ==========================================
            try:
                result_links = driver.find_elements(By.XPATH, "//div[@class='yuRUbf']//a")
                for link in result_links:
                    url = link.get_attribute("href")
                    if url and "google.com" not in url:
                        search_urls.append(url)
                search_urls = list(dict.fromkeys(search_urls))
                urls_str = "\n".join(search_urls)
            except:
                urls_str = ""

            # ==========================================
            # 💡 高精度チェック！名前は一致しているか？
            # ==========================================
            is_gbp_match = False
            match_reason = ""

            if gbp_name:
                # 株式会社などを削って純粋な名前同士で比較する
                clean_org = clean_company_name(org_name)
                clean_gbp = clean_company_name(gbp_name)

                # どちらかがどちらかの文字を含んでいれば「一致」とみなす
                if clean_org and clean_gbp and (clean_org in clean_gbp or clean_gbp in clean_org):
                    is_gbp_match = True
                else:
                    match_reason = f"名前不一致 (検索: {org_name} ≠ GBP: {gbp_name})"
            else:
                match_reason = "GBP表示なし"

            # ==========================================
            # 💡 スプレッドシートへの書き込み（分岐）
            #    ※ table_range='A1' を指定して、必ずA列から書き込む（列ずれ防止）
            # ==========================================
            now_str = time.strftime("%Y-%m-%d %H:%M:%S")
            try:
                if is_gbp_match:
                    print(f"✅ 名前が一致しました！ [GBPあり] シートに記録します。")
                    ws_found.append_row([
                        org_name, full_address, clean_address, gbp_name, gbp_category,
                        gbp_address, gbp_phone, gbp_website, gbp_map_url, urls_str, now_str
                    ], value_input_option='USER_ENTERED', table_range='A1')
                else:
                    # ==========================================
                    # 💡 GBPなし → 1ページ目のサイトを順番に開いて電話番号を探す
                    #    🚨 ただし「ページの会社名がCSVの顧客名と一致」したサイトの番号だけ採用する
                    # ==========================================
                    print(f"❌ {match_reason}。 [GBPなし]→1ページ目の全サイトから電話番号を探します。")

                    first_phone = ""        # 代表として記録する電話番号（社名＋住所一致の最初の1件）
                    verified_sites = []     # 社名・住所が一致して番号が取れたサイトの記録
                    skipped_mismatch = 0    # 番号はあったが社名or住所不一致で除外した件数

                    for site_url in search_urls:   # 💡 1ページ目すべてを探索
                        res = inspect_site(site_url, org_name, clean_address, driver=driver)

                        if res["phone"] and res["name_matched"] and res["address_matched"]:
                            # ✅ 社名＋住所が一致 ＆ 番号あり → 信用して採用
                            print(f"  ✅📞 {res['phone']}（{res['how']} / 社名・住所 一致） @ {site_url}")
                            if not first_phone:
                                first_phone = res["phone"]
                            verified_sites.append(
                                f"{res['phone']}（{res['how']} / 社名・住所一致）\n  └ URL: {site_url}\n  └ ページ会社名: {res['page_title']}"
                            )
                        elif res["phone"]:
                            # ⚠️ 番号はあるが社名or住所が一致しない → 情報不一致防止のため除外
                            skipped_mismatch += 1
                            ng = []
                            if not res["name_matched"]: ng.append("社名")
                            if not res["address_matched"]: ng.append("住所")
                            print(f"  ⚠️ 番号あり・但し{'/'.join(ng)}不一致のため除外 @ {site_url}（ページ: {res['page_title']}）")
                        else:
                            print(f"  ・番号なし @ {site_url}")

                        time.sleep(PHONE_FETCH_WAIT)

                    if first_phone:
                        phone_mark = "📞あり（社名・住所一致）"
                    else:
                        phone_mark = "電話番号なし"
                        if skipped_mismatch:
                            phone_mark += f"（社名/住所不一致で{skipped_mismatch}件除外）"

                    sites_str = "\n".join(verified_sites)
                    print(f"  → 判定: {phone_mark}（採用サイト {len(verified_sites)}件 / 除外 {skipped_mismatch}件）")

                    ws_not_found.append_row([
                        org_name, full_address, clean_address, match_reason,
                        phone_mark, first_phone, sites_str,
                        urls_str, now_str
                    ], value_input_option='USER_ENTERED', table_range='A1')
            except Exception as e:
                print(f"❌ スプレッドシートの書き込みに失敗しました: {e}")

            print("💤 ロボット検知を避けるため、10秒待機します...")
            time.sleep(10)

        print("\n✨ すべてのデータ抽出処理が完了しました！！")

except Exception as e:
    print("\n❌ 予期せぬ重大なエラーが発生しました:")
    print(traceback.format_exc())

finally:
    if driver:
        driver.quit()
