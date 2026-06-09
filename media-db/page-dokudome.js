/**
 * 独ドメげっと ページ
 * --------------------------------------------------------------------------
 * ・Excel/CSVで会社一覧を取り込み、まとめて「公式サイト（独自ドメイン）」を調査
 * ・1件だけの調査も可能
 * ・結果（業種/顧客名/住所/電話番号/どこで判断したか/URLトップ/会社概要等）をCSV出力
 * 除外ドメイン(ユーザー分)はストアに保存（管理者のみ）。大量リストはサーバー側で自動適用。
 */
(function () {
    const { createApp, ref, reactive, computed, onMounted, nextTick } = Vue;
    const { store, refreshIcons } = AppCore;
    const CSRF = window.__CSRF__ || '';
    const IS_ADMIN = window.__IS_ADMIN__ === true;

    /* ===== ファイル取り込みユーティリティ（register と同方式） ===== */
    const decodeBuffer = (buf) => {
        const bytes = new Uint8Array(buf);
        try { return new TextDecoder('utf-8', { fatal: true }).decode(bytes).replace(/^﻿/, ''); }
        catch (e) {
            try { return new TextDecoder('shift_jis').decode(bytes); }
            catch (e2) { return new TextDecoder('utf-8').decode(bytes); }
        }
    };
    const parseDelimited = (text, delim) => {
        const rows = []; let row = [], field = '', q = false;
        for (let i = 0; i < text.length; i++) {
            const ch = text[i];
            if (q) {
                if (ch === '"') { if (text[i + 1] === '"') { field += '"'; i++; } else q = false; }
                else field += ch;
            } else if (ch === '"') { q = true; }
            else if (ch === delim) { row.push(field); field = ''; }
            else if (ch === '\n') { row.push(field); rows.push(row); row = []; field = ''; }
            else if (ch !== '\r') { field += ch; }
        }
        if (field !== '' || row.length) { row.push(field); rows.push(row); }
        return rows;
    };
    const medianCols = (rows) => {
        const c = rows.filter(r => r.some(x => String(x).trim() !== '')).map(r => r.length).sort((a, b) => a - b);
        return c.length ? c[Math.floor(c.length / 2)] : 0;
    };
    const parseAuto = (text) => {
        const a = parseDelimited(text, ','), b = parseDelimited(text, '\t');
        return medianCols(b) > medianCols(a) ? b : a;
    };
    const parseFileToRows = (file) => new Promise((resolve, reject) => {
        const name = (file.name || '').toLowerCase();
        const reader = new FileReader();
        reader.onerror = () => reject(new Error('ファイルの読み込みに失敗しました。'));
        if (name.endsWith('.xlsx') || name.endsWith('.xls')) {
            if (typeof XLSX === 'undefined') { reject(new Error('Excel読み込み機能を準備できませんでした。CSVでお試しください。')); return; }
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
            reader.onload = () => resolve(parseAuto(decodeBuffer(reader.result)).map(r => r.map(c => c.trim())));
            reader.readAsArrayBuffer(file);
        }
    });

    /** 見出し行を見て列位置を決める（無ければ 業種/顧客名/住所/電話番号 の順とみなす） */
    const mapColumns = (rows) => {
        const first = rows[0] || [];
        const isHeader = first.some(c => /顧客名|会社名|業種|電話|住所/.test(c));
        if (isHeader) {
            const find = (re, dflt) => { const i = first.findIndex(c => re.test(c)); return i >= 0 ? i : dflt; };
            return { start: 1, idx: { industry: find(/業種/, 0), name: find(/顧客名|会社名|名称/, 1), address: find(/住所|所在地/, 2), phone: find(/電話/, 3) } };
        }
        return { start: 0, idx: { industry: 0, name: 1, address: 2, phone: 3 } };
    };

    createApp({
        setup() {
            const form      = reactive({ name: '', industry: '', address: '', phone: '' });
            const results   = ref([]);     // {industry,name,address,phone,evidence,topUrl,pageUrl,found,pending,at}
            const searching = ref(false);

            // 取り込み
            const importFileName = ref('');
            const importMessage  = ref('');
            const importError    = ref(false);
            const companies      = ref([]);   // 取り込んだ会社 {industry,name,address,phone}

            // 一括実行
            const bulkRunning = ref(false);
            const bulkDone    = ref(0);
            const bulkTotal   = ref(0);
            let   cancelFlag  = false;

            // 除外ドメイン編集
            const excludeText  = ref((store.excludeDomains || []).join('\n'));
            const excludeCount = computed(() => (store.excludeDomains || []).length);
            const saveExclude = () => {
                const list = excludeText.value.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
                store.excludeDomains = Array.from(new Set(list));
            };

            // 1社を検索（結果オブジェクトを更新）
            const searchCompany = async (c) => {
                const res = await fetch('dokudome-search.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                    body: JSON.stringify({ name: c.name, industry: c.industry, address: c.address, phone: c.phone }),
                });
                let data = {};
                try { data = await res.json(); } catch (e) { /* noop */ }
                if (!res.ok || data.error) throw new Error(data.message || data.error || ('HTTP ' + res.status));
                return data;
            };

            const onFileChange = async (e) => {
                const file = e.target.files && e.target.files[0];
                e.target.value = '';
                if (!file) return;
                importFileName.value = file.name;
                importError.value = false;
                importMessage.value = '読み込み中...';
                try {
                    const rows = (await parseFileToRows(file)).filter(r => r.some(c => String(c).trim() !== ''));
                    if (rows.length === 0) { companies.value = []; importError.value = true; importMessage.value = 'データが見つかりませんでした。'; return; }
                    const { start, idx } = mapColumns(rows);
                    const list = [];
                    for (let i = start; i < rows.length; i++) {
                        const r = rows[i];
                        const name = (r[idx.name] || '').trim();
                        if (!name) continue;
                        list.push({
                            industry: (r[idx.industry] || '').trim(),
                            name,
                            address: (r[idx.address] || '').trim(),
                            phone: (r[idx.phone] || '').trim(),
                        });
                    }
                    companies.value = list;
                    importMessage.value = list.length
                        ? `「${file.name}」から ${list.length} 件を読み込みました。「まとめて調べる」を押してください。`
                        : '会社名の列が見つかりませんでした。列の順番（業種/顧客名/住所/電話番号）をご確認ください。';
                    importError.value = list.length === 0;
                } catch (err) {
                    companies.value = [];
                    importError.value = true;
                    importMessage.value = '読み込めませんでした：' + (err && err.message ? err.message : err);
                }
            };

            // まとめて調査（1件ずつ順番に。中止可能）
            const runBulk = async () => {
                if (!IS_ADMIN) { alert('検索は管理者のみ実行できます。'); return; }
                if (!companies.value.length || bulkRunning.value) return;
                bulkRunning.value = true; cancelFlag = false;
                bulkTotal.value = companies.value.length; bulkDone.value = 0;
                for (const c of companies.value) {
                    if (cancelFlag) break;
                    const row = reactive({ ...c, evidence: '', topUrl: '', pageUrl: '', found: false, pending: true, at: '' });
                    results.value.unshift(row);
                    nextTick(refreshIcons);
                    try {
                        const data = await searchCompany(c);
                        row.found = !!data.found;
                        row.evidence = data.evidence || '';
                        row.topUrl = data.topUrl || '';
                        row.pageUrl = data.pageUrl || '';
                    } catch (e) {
                        row.evidence = 'エラー: ' + ((e && e.message) ? e.message : e);
                    } finally {
                        row.pending = false;
                        row.at = new Date().toLocaleString('ja-JP', { hour12: false });
                        bulkDone.value++;
                        nextTick(refreshIcons);
                    }
                }
                bulkRunning.value = false;
                companies.value = [];
                importFileName.value = '';
                importMessage.value = '';
            };
            const cancelBulk = () => { cancelFlag = true; };

            // 1件だけ調査
            const runOne = async () => {
                if (!IS_ADMIN) { alert('検索は管理者のみ実行できます。'); return; }
                if (!form.name.trim() && !form.phone.trim()) { alert('顧客名または電話番号を入力してください。'); return; }
                if (searching.value) return;
                searching.value = true;
                const c = { industry: form.industry, name: form.name, address: form.address, phone: form.phone };
                const row = reactive({ ...c, evidence: '', topUrl: '', pageUrl: '', found: false, pending: true, at: '' });
                results.value.unshift(row);
                nextTick(refreshIcons);
                try {
                    const data = await searchCompany(c);
                    row.found = !!data.found;
                    row.evidence = data.evidence || '';
                    row.topUrl = data.topUrl || '';
                    row.pageUrl = data.pageUrl || '';
                } catch (e) {
                    row.evidence = 'エラー: ' + ((e && e.message) ? e.message : e);
                    alert('検索に失敗しました：\n' + ((e && e.message) ? e.message : e));
                } finally {
                    row.pending = false;
                    row.at = new Date().toLocaleString('ja-JP', { hour12: false });
                    searching.value = false;
                    nextTick(refreshIcons);
                }
            };

            const clearResults = () => { if (confirm('結果をすべてクリアしますか？')) results.value = []; };

            // CSV出力（画像の項目順）
            const exportCsvRows = () => {
                if (!results.value.length) { alert('出力する結果がありません。'); return; }
                const esc = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
                let csv = '業種,顧客名,住所,電話番号,どこで判断したか,URLトップ,会社概要等\n';
                // 表示は新しい順なので、出力は取り込み順（古い順）に戻す
                [...results.value].reverse().forEach(r => {
                    csv += [r.industry, r.name, r.address, r.phone, r.evidence, r.topUrl, r.pageUrl].map(esc).join(',') + '\n';
                });
                const bom = new Uint8Array([0xEF, 0xBB, 0xBF]);
                const blob = new Blob([bom, csv], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url; a.download = '独自ドメイン調査結果.csv';
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
                URL.revokeObjectURL(url);
            };

            onMounted(() => nextTick(refreshIcons));

            return {
                store, form, results, searching,
                importFileName, importMessage, importError, companies, onFileChange,
                bulkRunning, bulkDone, bulkTotal, runBulk, cancelBulk,
                runOne, clearResults,
                excludeText, excludeCount, saveExclude,
                exportResults: exportCsvRows, exportCsv: exportCsvRows,   // ヘッダーのCSVボタンも結果出力
                isAdmin: IS_ADMIN,
            };
        }
    }).mount('#app');
})();
