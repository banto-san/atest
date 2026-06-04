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

    const todayStr = new Date().toISOString().slice(0, 10);

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
                const delim = text.includes('\t') ? '\t' : ',';
                resolve(parseDelimited(text, delim).map(r => r.map(c => c.trim())));
            };
            reader.readAsArrayBuffer(file);
        }
    });

    createApp({
        setup() {
            const mediaList = computed(() => store.media);

            const newClient = ref({ name: '', industry: '', orderDate: todayStr, address: '', sourceMediaId: '', usedMediaIds: [] });
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
                    name: newClient.value.name,
                    industry: newClient.value.industry,
                    orderDate: newClient.value.orderDate,
                    address: newClient.value.address,
                    sourceMediaId: newClient.value.sourceMediaId,
                    usedMediaIds: finalMediaIds,
                });

                newClient.value = { name: '', industry: '', orderDate: todayStr, address: '', sourceMediaId: '', usedMediaIds: [] };
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
                    // 1行目が見出し（顧客名/会社名）なら飛ばす
                    if (idx === 0 && /顧客名|会社名|name/i.test(cols[0] || '')) return;

                    const name = (cols[0] || '').trim();
                    if (!name) { skipCount++; return; }

                    // 列: 顧客名, 業界, 受注日, 住所, リスト元媒体, 利用媒体(最後・カンマ区切り)
                    const industry = (cols[1] || '').trim();
                    const orderDate = (cols[2] || '').trim() || todayStr;
                    const address = (cols[3] || '').trim();
                    const sourceMediaId = (cols[4] || '').trim() ? findOrCreateMedia(cols[4].trim()) : '';
                    const mediaNamesStr = cols.slice(5).join(',');

                    const mediaIds = [];
                    mediaNamesStr.split(/[,、\s]+/).filter(Boolean).forEach(mName => {
                        const found = store.media.find(m => m.name.toLowerCase() === mName.toLowerCase() || mName.includes(m.name));
                        mediaIds.push(found ? found.id : findOrCreateMedia(mName, 'unknown'));
                    });

                    store.clients.push({
                        id: genId('c'),
                        name, industry, orderDate, address, sourceMediaId,
                        usedMediaIds: mediaIds,
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
