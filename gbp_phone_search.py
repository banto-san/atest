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

# 💡 会社名を比較するために、余計な文字（株式会社やスペース）を削る魔法の関数
def clean_company_name(name):
    if not name: return ""
    remove_words = ["株式会社", "有限会社", "合同会社", "一般社団法人", "財団法人", "医療法人", " ", "　", "・", "（", "）", "(", ")"]
    res = name
    for w in remove_words:
        res = res.replace(w, "")
    return res.lower()

# 💡 1つのサイトを開いて電話番号を探す関数
#    戻り値: (見つかったか, 電話番号, 取得方法)
def find_phone_on_site(url, driver=None):
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
        return (False, "", "")

    # --- tel: リンクを最優先（サイト側が「電話番号です」と宣言しているので確実） ---
    tel_links = re.findall(r'href=["\']tel:([+\d\-\(\)\s]+)["\']', html, re.IGNORECASE)
    if tel_links:
        num = re.sub(r'[^\d+]', '', tel_links[0])
        return (True, num, "tel:リンク")

    # --- 本文テキストから正規表現で探す（保険） ---
    m = PHONE_PATTERN.search(html)
    if m:
        return (True, m.group(), "本文テキスト")

    return (False, "", "")

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
    except:
        ws_found = sh.add_worksheet(title=SHEET_FOUND, rows="1000", cols="12")
        ws_found.append_row([
            "検索した名前", "元の住所", "検索用住所", "【GBP】会社名", "【GBP】業種",
            "【GBP】住所", "【GBP】電話番号", "【GBP】ウェブサイト", "【GBP】マップURL", "【検索結果】1ページ目URL", "チェック日時"
        ])

    # --- GBPなしシートの準備（電話番号の列を追加！） ---
    try: ws_not_found = sh.worksheet(SHEET_NOT_FOUND)
    except:
        ws_not_found = sh.add_worksheet(title=SHEET_NOT_FOUND, rows="1000", cols="10")
        ws_not_found.append_row([
            "検索した名前", "元の住所", "検索用住所", "判定結果（理由）",
            "電話番号印", "発見した電話番号（代表）", "番号が見つかったサイト",
            "【検索結果】1ページ目URL", "チェック日時"
        ])

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
            # ==========================================
            now_str = time.strftime("%Y-%m-%d %H:%M:%S")
            try:
                if is_gbp_match:
                    print(f"✅ 名前が一致しました！ [GBPあり] シートに記録します。")
                    ws_found.append_row([
                        org_name, full_address, clean_address, gbp_name, gbp_category,
                        gbp_address, gbp_phone, gbp_website, gbp_map_url, urls_str, now_str
                    ], value_input_option='USER_ENTERED')
                else:
                    # ==========================================
                    # 💡 GBPなし → 1ページ目のサイトを順番に開いて電話番号を探す
                    # ==========================================
                    print(f"❌ {match_reason}。 [GBPなし]→1ページ目のサイトから電話番号を探します。")

                    first_phone = ""        # 代表として記録する電話番号（最初に見つかったもの）
                    sites_with_phone = []   # 電話番号が見つかったサイトのメモ

                    for site_url in search_urls:   # 💡 1ページ目すべてを探索
                        ok, phone, how = find_phone_on_site(site_url, driver=driver)
                        if ok:
                            print(f"  📞 電話番号を発見！ {phone}（{how}） @ {site_url}")
                            if not first_phone:
                                first_phone = phone
                            sites_with_phone.append(f"{phone}（{how}） {site_url}")
                        else:
                            print(f"  ・電話番号なし @ {site_url}")
                        time.sleep(PHONE_FETCH_WAIT)

                    phone_mark = "📞あり" if first_phone else "電話番号なし"
                    sites_str = "\n".join(sites_with_phone)
                    print(f"  → 判定: {phone_mark}（見つかったサイト {len(sites_with_phone)}件）")

                    ws_not_found.append_row([
                        org_name, full_address, clean_address, match_reason,
                        phone_mark, first_phone, sites_str,
                        urls_str, now_str
                    ], value_input_option='USER_ENTERED')
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
