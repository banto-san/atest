/**
 * 受注逆引きDB 共通フロント処理
 * --------------------------------------------------------------------------
 * 全ページで共有する単一データストアと、保存・集計・CSV出力などの共通処理。
 * PHP(layout_bottom.php)が埋め込んだ window.__APP_DATA__ / window.__CSRF__ を使う。
 *
 * 各ページの JS からは window.AppCore 経由で参照する。
 */
(function () {
    const { reactive, watch, nextTick } = Vue;

    const seed = window.__APP_DATA__ || { users: [], media: [], clients: [] };
    const CSRF = window.__CSRF__ || '';
    const IS_ADMIN = window.__IS_ADMIN__ === true;

    // ===== 全ページ共通の単一データストア =====
    const store = reactive({
        users:   seed.users   || [],
        media:   seed.media   || [],
        clients: seed.clients || [],
        isSyncing: false,
        syncError: false,
    });

    // ===== サーバ保存（api.php へ POST） =====
    let saveTimer = null;

    async function persist() {
        store.isSyncing = true;
        store.syncError = false;
        try {
            const res = await fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF,
                },
                body: JSON.stringify({
                    users: store.users,
                    media: store.media,
                    clients: store.clients,
                }),
            });
            if (!res.ok) throw new Error('HTTP ' + res.status);
            // 保存中表示を少しだけ見せる
            setTimeout(() => { store.isSyncing = false; }, 400);
        } catch (e) {
            console.error('保存に失敗しました:', e);
            store.isSyncing = false;
            store.syncError = true;
        }
    }

    /** 変更をまとめて保存（短時間の連続変更はデバウンス） */
    function save() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(persist, 300);
    }

    // データ（users/media/clients）が変わったら自動保存。
    // 初期描画では発火しない（実ユーザー操作による変更時のみ）。
    watch(
        () => [store.users, store.media, store.clients],
        save,
        { deep: true }
    );

    // ===== 媒体ユーティリティ =====
    /** 媒体IDの配列 → 媒体オブジェクトの配列 */
    function getMediaDetails(mediaIds) {
        if (!mediaIds) return [];
        return mediaIds.map(id => store.media.find(m => m.id === id)).filter(Boolean);
    }

    /** 対象顧客群の「利用媒体」を集計し、多い順のランキング配列を返す */
    function calculateRanking(targetClients) {
        const counts = {};
        targetClients.forEach(client => {
            (client.usedMediaIds || []).forEach(id => {
                counts[id] = (counts[id] || 0) + 1;
            });
        });
        return Object.keys(counts)
            .map(id => ({ media: store.media.find(m => m.id === id), count: counts[id] }))
            .filter(item => item.media)
            .sort((a, b) => b.count - a.count);
    }

    // ===== CSV出力（顧客リスト共通フォーマット） =====
    function exportClientsCSV(clientsToExport, filename) {
        if (!clientsToExport || clientsToExport.length === 0) {
            alert('出力するデータがありません。');
            return;
        }
        const esc = (v) => '"' + String(v ?? '').replace(/"/g, '""') + '"';
        let csv = '顧客名,業界,受注日,住所,他利用媒体\n';
        clientsToExport.forEach(client => {
            const mediaNames = getMediaDetails(client.usedMediaIds).map(m => m.name).join(' / ');
            csv += [client.name, client.industry, client.orderDate, client.address, mediaNames].map(esc).join(',') + '\n';
        });
        const bom = new Uint8Array([0xEF, 0xBB, 0xBF]); // Excelの文字化け対策
        const blob = new Blob([bom, csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    /** Lucideアイコンを（再）描画 */
    function refreshIcons() {
        if (window.lucide) window.lucide.createIcons();
    }

    // ===== 他媒体検索（search.php を呼ぶ） =====
    // 1顧客を「会社名＋住所」で検索し、見つかったURL/ドメインを保存して返す
    async function searchClientMedia(client) {
        const res = await fetch('search.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify({ id: client.id }),
        });
        let data = {};
        try { data = await res.json(); } catch (e) { /* noop */ }
        if (!res.ok || data.error) {
            throw new Error(data.message || data.error || ('HTTP ' + res.status));
        }
        // ストアの該当顧客へ反映（自動保存もかかる）
        const c = store.clients.find(x => x.id === client.id);
        if (c) {
            c.foundMedia = data.foundMedia || [];
            c.searchedAt = data.searchedAt || '';
            c.searchNote = data.note || '';
        }
        return data;
    }

    /** 当月のOpenAI使用額を取得（cost.php）。未設定や失敗時も画面を壊さない。 */
    async function fetchMonthlyCost() {
        try {
            const res = await fetch('cost.php');
            return await res.json();
        } catch (e) {
            return null;
        }
    }

    /** 対象顧客群の foundMedia から「ドメイン × 何社で重複したか」のランキングを作る */
    function foundDomainsRanking(clients) {
        const counts = {};
        clients.forEach(c => {
            const domains = new Set((c.foundMedia || []).map(m => m.domain).filter(Boolean));
            domains.forEach(d => { counts[d] = (counts[d] || 0) + 1; });
        });
        return Object.entries(counts)
            .map(([domain, count]) => ({ domain, count }))
            .sort((a, b) => b.count - a.count || a.domain.localeCompare(b.domain));
    }

    // 保存状態インジケーター（v-ifで出る loader アイコン）の再描画
    watch(() => [store.isSyncing, store.syncError], () => nextTick(refreshIcons));

    // ===== 公開 =====
    window.AppCore = { store, isAdmin: IS_ADMIN, save, getMediaDetails, calculateRanking, exportClientsCSV, refreshIcons, searchClientMedia, foundDomainsRanking, fetchMonthlyCost };
})();



