/**
 * データ登録・読込 ページ
 * --------------------------------------------------------------------------
 * ・顧客を1件ずつ登録
 * ・Excel(.xlsx)/CSV ファイルを選んで一括インポート
 * ・登録済み顧客の一覧表示／個別削除／選択削除／全件削除
 */
(function () {
    const { createApp, ref, computed, onMounted, watch, nextTick } = Vue;
    const { store, exportClientsCSV, refreshIcons } = AppCore;

    /** ランダムなID生成（衝突しにくい接頭辞付き） */
    const genId = (prefix) => prefix + Date.now() + Math.floor(Math.random() * 100000);

    /** 媒体名から既存IDを返す。無ければ新規作成してIDを返す。 */
    const findOrCreateMedia = (name, domain = '-') => {
        const n = (name || '').trim();
        if (!n) return null;
        const found = store.media.find(m => m.name.toLowerCase() === n.toLowerCase());
        if (found) return found.id;
        const id = genId('m');
        store.media.push({ id, name: n, domain });
        return id;
    };

    /** リスト元媒体名から「申込日：…」等の余分な注記を取り除く */
    const cleanMediaName = (s) => (s || '').replace(/[\s　]*申込日[：:].*$/, '').trim();

    /** Dateを YYYY-MM-DD（ローカル）に整形 */
    const ymd = (dt) => `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;

    /**
     * その年の日本の祝日（振替休日・国民の休日を含む）の Set を作る。
     * 1980〜2099年で有効（春分/秋分は計算式）。年が変わっても自動対応。
     */
    const jpHolidays = (year) => {
        const set = new Set();
        const f = (m, d) => `${year}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        // 固定日の祝日
        [[1, 1], [2, 11], [2, 23], [4, 29], [5, 3], [5, 4], [5, 5], [8, 11], [11, 3], [11, 23]]
            .forEach(([m, d]) => set.add(f(m, d)));
        // ハッピーマンデー（第n月曜）
        const nthMon = (m, n) => {
            let c = 0;
            for (let d = 1; d <= 31; d++) {
                const dt = new Date(year, m - 1, d);
                if (dt.getMonth() !== m - 1) break;
                if (dt.getDay() === 1 && ++c === n) return d;
            }
            return null;
        };
        set.add(f(1, nthMon(1, 2)));   // 成人の日
        set.add(f(7, nthMon(7, 3)));   // 海の日
        set.add(f(9, nthMon(9, 3)));   // 敬老の日
        set.add(f(10, nthMon(10, 2))); // スポーツの日
        // 春分の日・秋分の日（計算式）
        set.add(f(3, Math.floor(20.8431 + 0.242194 * (year - 1980) - Math.floor((year - 1980) / 4))));
        set.add(f(9, Math.floor(23.2488 + 0.242194 * (year - 1980) - Math.floor((year - 1980) / 4))));
        const base = [...set];
        // 振替休日（日曜が祝日なら次の非祝日を休日に）
        base.forEach(s => {
            const dt = new Date(s + 'T00:00:00');
            if (dt.getDay() === 0) {
                const nx = new Date(dt);
                do { nx.setDate(nx.getDate() + 1); } while (set.has(ymd(nx)));
                set.add(ymd(nx));
            }
        });
        // 国民の休日（祝日に挟まれた平日）
        base.forEach(s => {
            const dt = new Date(s + 'T00:00:00');
            const mid = new Date(dt); mid.setDate(mid.getDate() + 1);
            const p2 = new Date(dt); p2.setDate(p2.getDate() + 2);
            if (base.includes(ymd(p2)) && !set.has(ymd(mid)) && mid.getDay() !== 0) set.add(ymd(mid));
        });
        return set;
    };

    const _holidayCache = {};
    const isHolidayOrWeekend = (dt) => {
        const day = dt.getDay();
        if (day === 0 || day === 6) return true; // 日・土
        const y = dt.getFullYear();
        if (!_holidayCache[y]) _holidayCache[y] = jpHolidays(y);
        return _holidayCache[y].has(ymd(dt));
    };

    /** 基準日(既定は今日)からさかのぼった「前営業日」を YYYY-MM-DD で返す（土日祝・連休を飛ばす） */
    const previousBusinessDay = (from = new Date()) => {
        const d = new Date(from.getFullYear(), from.getMonth(), from.getDate());
        do { d.setDate(d.getDate() - 1); } while (isHolidayOrWeekend(d));
        return ymd(d);
    };

    // 取り込み時に使う「前営業日」（ページ表示時点で計算）
    const prevBizDay = previousBusinessDay();

    /** ArrayBuffer を文字列へ。UTF-8で失敗したら Shift_JIS とみなす（Excelの日本語CSV対策）。 */
    const decodeBuffer = (buf) => {
        const bytes = new Uint8Array(buf);
        try {
            return new TextDecoder('utf-8', { fatal: true }).decode(bytes).replace(/^﻿/, '');
        } catch (e) {
            try { return new TextDecoder('shift_jis').decode(bytes); }
            catch (e2) { return new TextDecoder('utf-8').decode(bytes); }
        }
    };

    /** 区切りテキストを行×列の二次元配列に。"..." で囲まれたカンマ・改行も正しく扱う。 */
    const parseDelimited = (text, delim) => {
        const rows = [];
        let row = [], field = '', inQuotes = false;
        for (let i = 0; i < text.length; i++) {
            const ch = text[i];
            if (inQuotes) {
                if (ch === '"') {
                    if (text[i + 1] === '"') { field += '"'; i++; }
                    else inQuotes = false;
                } else field += ch;
            } else if (ch === '"') {
                inQuotes = true;
            } else if (ch === delim) {
                row.push(field); field = '';
            } else if (ch === '\n') {
                row.push(field); rows.push(row); row = []; field = '';
            } else if (ch !== '\r') {
                field += ch;
            }
        }
        if (field !== '' || row.length) { row.push(field); rows.push(row); }
        return rows;
    };

    /** 空行を除いた行の「列数の中央値」（区切り記号の良し悪しを測る指標） */
    const medianCols = (rows) => {
        const counts = rows.filter(r => r.some(c => String(c).trim() !== '')).map(r => r.length).sort((a, b) => a - b);
        return counts.length ? counts[Math.floor(counts.length / 2)] : 0;
    };

    /** カンマ区切り・タブ区切りの両方で試し、列が多く取れた方を採用（紛れ込んだタブ等で誤判定しない） */
    const parseAuto = (text) => {
        const byComma = parseDelimited(text, ',');
        const byTab = parseDelimited(text, '\t');
        return medianCols(byTab) > medianCols(byComma) ? byTab : byComma;
    };

    /** 選択ファイル(Excel/CSV)を 行×列 の二次元配列にして返す（Promise） */
    const parseFileToRows = (file) => new Promise((resolve, reject) => {
        const name = (file.name || '').toLowerCase();
        const reader = new FileReader();
        reader.onerror = () => reject(new Error('ファイルの読み込みに失敗しました。'));

        if (name.endsWith('.xlsx') || name.endsWith('.xls')) {
            if (typeof XLSX === 'undefined') {
                reject(new Error('Excel読み込み機能を準備できませんでした。CSV形式でお試しください。'));
                return;
            }
            reader.onload = () => {
                try {
                    const wb = XLSX.read(new Uint8Array(reader.result), { type: 'array' });
                    const ws = wb.Sheets[wb.SheetNames[0]];
                    const rows = XLSX.utils.sheet_to_json(ws, { header: 1, raw: false, defval: '' });
                    resolve(rows.map(r => r.map(c => String(c == null ? '' : c).trim())));
                } catch (e) { reject(e); }
            };
            reader.readAsArrayBuffer(file);
        } else {
            reader.onload = () => {
                const text = decodeBuffer(reader.result);
                resolve(parseAuto(text).map(r => r.map(c => c.trim())));
            };
            reader.readAsArrayBuffer(file);
        }
    });

    createApp({
        setup() {
            const mediaList = computed(() => store.media);

            const newClient = ref({ name: '', industry: '', orderDate: prevBizDay, address: '', sourceMediaId: '', usedMediaIds: [] });
            const newClientManualFlags = ref('');
            const successMessage = ref('');

            const importFileName = ref('');
            const importRows = ref([]);   // 取り込み待ちの行データ
            const importMessage = ref('');
            const importError = ref(false);

            const selectedClientIds = ref([]);

            // 媒体IDからリスト元媒体名を引く
            const sourceMediaName = (client) => {
                const m = store.media.find(x => x.id === client.sourceMediaId);
                return m ? m.name : '';
            };

            // チェックボックスのリストから媒体を削除（顧客側の参照も除去）
            const deleteMedia = (index) => {
                const media = store.media[index];
                if (!media) return;
                const deletedId = media.id;
                store.media.splice(index, 1);
                store.clients.forEach(client => {
                    client.usedMediaIds = (client.usedMediaIds || []).filter(id => id !== deletedId);
                });
                successMessage.value = `フラグ「${media.name}」を削除しました。`;
                setTimeout(() => { successMessage.value = ''; }, 3000);
            };

            const registerSingleClient = () => {
                const finalMediaIds = [...newClient.value.usedMediaIds];

                // 手動入力フラグ（カンマ/読点区切り）を媒体マスタへ反映
                if (newClientManualFlags.value.trim() !== '') {
                    newClientManualFlags.value.split(/[,、]+/).map(s => s.trim()).filter(Boolean).forEach(flagName => {
                        const id = findOrCreateMedia(flagName);
                        if (id && !finalMediaIds.includes(id)) finalMediaIds.push(id);
                    });
                }

                store.clients.push({
                    id: genId('c'),
                    serial: '',   // 手動登録はNyoiBowシリアルなし
                    name: newClient.value.name,
                    industry: newClient.value.industry,
                    orderDate: newClient.value.orderDate,
                    address: newClient.value.address,
                    sourceMediaId: newClient.value.sourceMediaId,
                    usedMediaIds: finalMediaIds,
                });

                newClient.value = { name: '', industry: '', orderDate: prevBizDay, address: '', sourceMediaId: '', usedMediaIds: [] };
                newClientManualFlags.value = '';
                successMessage.value = '顧客データを1件登録しました！';
                setTimeout(() => { successMessage.value = ''; }, 3000);
            };

            // ファイル選択時 → 解析して取り込み待ちにする
            const onFileChange = async (e) => {
                const file = e.target.files && e.target.files[0];
                e.target.value = '';   // 同じファイルを選び直せるようにクリア
                if (!file) return;
                importFileName.value = file.name;
                importError.value = false;
                importMessage.value = '読み込み中...';
                try {
                    const rows = await parseFileToRows(file);
                    importRows.value = rows.filter(r => r.some(c => String(c).trim() !== ''));
                    if (importRows.value.length === 0) {
                        importError.value = true;
                        importMessage.value = 'ファイルにデータが見つかりませんでした。';
                    } else {
                        importMessage.value = `「${file.name}」を読み込みました（${importRows.value.length}行）。内容を確認して「取り込む」を押してください。`;
                    }
                } catch (err) {
                    importRows.value = [];
                    importError.value = true;
                    importMessage.value = '読み込めませんでした：' + (err && err.message ? err.message : err);
                }
            };

            // 取り込み待ちの行データを顧客として登録
            const processImport = () => {
                if (importRows.value.length === 0) {
                    importError.value = true;
                    importMessage.value = '先にファイルを選択してください。';
                    return;
                }

                let successCount = 0;
                let skipCount = 0;

                importRows.value.forEach((cols, idx) => {
                    // 1行目が見出し（NyoiBowマスタシリアル/作業日/顧客名 等）なら飛ばす
                    if (idx === 0 && /NyoiBow|シリアル|顧客名|会社名|作業日|リストカテゴリー/i.test(cols.join(','))) return;

                    // 列順: ①NyoiBowマスタシリアル ②作業日 ③顧客名 ④新住所 ⑤業種 ⑥リストカテゴリー
                    const serial = (cols[0] || '').trim();
                    // cols[1] = 作業日 → 使わない（受注日は取り込み時の「前営業日」を自動設定）
                    const name = (cols[2] || '').trim();
                    if (!name) { skipCount++; return; }
                    const address    = (cols[3] || '').trim();
                    const industry   = (cols[4] || '').trim();
                    const sourceName = cleanMediaName(cols[5] || '');
                    const sourceMediaId = sourceName ? findOrCreateMedia(sourceName) : '';
                    const orderDate  = prevBizDay;

                    store.clients.push({
                        id: genId('c'),
                        serial, name, industry, orderDate, address, sourceMediaId,
                        usedMediaIds: [],   // 「他に利用している媒体」は取り込み後にダッシュボードの検索で確認
                    });
                    successCount++;
                });

                if (successCount > 0) {
                    importError.value = false;
                    importMessage.value = `${successCount}件のデータを取り込みました！(スキップ: ${skipCount}件)`;
                    importRows.value = [];
                    importFileName.value = '';
                } else {
                    importError.value = true;
                    importMessage.value = '有効なデータが見つかりませんでした。列の順番をご確認ください。';
                }
                setTimeout(() => { importMessage.value = ''; }, 6000);
            };

            // ===== 削除系 =====
            const allSelected = computed(() => store.clients.length > 0 && selectedClientIds.value.length === store.clients.length);

            const toggleSelectAll = (e) => {
                selectedClientIds.value = e.target.checked ? store.clients.map(c => c.id) : [];
            };

            const deleteOneClient = (id) => {
                if (!confirm('この顧客を削除しますか？')) return;
                store.clients = store.clients.filter(c => c.id !== id);
                selectedClientIds.value = selectedClientIds.value.filter(x => x !== id);
            };

            const deleteSelected = () => {
                const n = selectedClientIds.value.length;
                if (n === 0) return;
                if (!confirm(`選択した ${n} 件の顧客を削除しますか？`)) return;
                const set = new Set(selectedClientIds.value);
                store.clients = store.clients.filter(c => !set.has(c.id));
                selectedClientIds.value = [];
            };

            const deleteAllClients = () => {
                const n = store.clients.length;
                if (n === 0) return;
                if (!confirm(`本当に全 ${n} 件の顧客を削除しますか？\nこの操作は元に戻せません。`)) return;
                store.clients = [];
                selectedClientIds.value = [];
            };

            // ヘッダーのCSVボタン → 全顧客を出力
            const exportCsv = () => exportClientsCSV(store.clients, '全顧客リスト.csv');

            // 媒体・顧客リストが変わるとアイコンが増減するので再描画
            watch(() => [store.media.length, store.clients.length], () => nextTick(refreshIcons));
            onMounted(() => nextTick(refreshIcons));

            return {
                store, mediaList,
                newClient, newClientManualFlags, successMessage, registerSingleClient, deleteMedia,
                importFileName, importRows, importMessage, importError, onFileChange, processImport,
                selectedClientIds, allSelected, toggleSelectAll, deleteOneClient, deleteSelected, deleteAllClients,
                sourceMediaName, exportCsv,
            };
        }
    }).mount('#app');
})();
