/**
 * 受注一覧・ランキング ページ
 * --------------------------------------------------------------------------
 * 期間で受注顧客を絞り込み、一覧と「併用媒体ランキング」を表示する。
 */
(function () {
    const { createApp, ref, computed, onMounted, watch, nextTick } = Vue;
    const { store, exportClientsCSV, refreshIcons, searchClientMedia, foundDomainsRanking } = AppCore;
    // app-core.js が古くても壊れないよう保険（本来は AppCore.isValidDomain を使用）
    const isValidDomain = AppCore.isValidDomain || ((d) => typeof d === 'string' && /\./.test(d));

    createApp({
        setup() {
            // 初期表示は「前日」分（日々の受注チェック用）。空なら「全期間にリセット」で全件表示。
            const d = new Date();
            d.setDate(d.getDate() - 1);
            const yesterday = d.toISOString().slice(0, 10);

            const filterStartDate = ref(yesterday);
            const filterEndDate   = ref(yesterday);

            const filteredClientsByDate = computed(() => {
                return store.clients.filter(c => {
                    if (!filterStartDate.value && !filterEndDate.value) return true;
                    const orderDate = new Date(c.orderDate);
                    let afterStart = true, beforeEnd = true;
                    if (filterStartDate.value) afterStart = orderDate >= new Date(filterStartDate.value);
                    if (filterEndDate.value)   beforeEnd  = orderDate <= new Date(filterEndDate.value);
                    return afterStart && beforeEnd;
                }).sort((a, b) => new Date(b.orderDate) - new Date(a.orderDate));
            });

            const dateRangeText = computed(() => {
                if (!filterStartDate.value && !filterEndDate.value) return '全期間';
                if (filterStartDate.value && filterEndDate.value)   return `${filterStartDate.value} 〜 ${filterEndDate.value}`;
                if (filterStartDate.value) return `${filterStartDate.value} 以降`;
                if (filterEndDate.value)   return `${filterEndDate.value} 以前`;
                return '';
            });

            const resetDateFilter = () => {
                filterStartDate.value = '';
                filterEndDate.value = '';
            };

            const deleteClient = (id) => {
                if (confirm('この顧客データを削除してもよろしいですか？')) {
                    store.clients = store.clients.filter(c => c.id !== id);
                }
            };

            // 受注リスト元（獲得元の媒体）
            const sourceMedia = (client) => store.media.find(m => m.id === client.sourceMediaId) || null;

            // ===== 他媒体検索（このサイト自身が検索） =====
            const searchingId = ref('');     // 検索中の顧客ID（ボタンのぐるぐる用）
            const searchError = ref('');

            const searchOtherMedia = async (client) => {
                if (searchingId.value) return;   // 同時実行を防ぐ
                searchingId.value = client.id;
                searchError.value = '';
                try {
                    await searchClientMedia(client);
                } catch (e) {
                    searchError.value = (e && e.message) ? e.message : String(e);
                    alert('検索に失敗しました：\n' + searchError.value);
                } finally {
                    searchingId.value = '';
                    nextTick(refreshIcons);
                }
            };

            // 未取得の顧客をまとめて検索（1件ずつ順番に）
            const searchAllPending = async () => {
                const targets = filteredClientsByDate.value.filter(c => !c.searchedAt);
                if (targets.length === 0) { alert('未取得の顧客はありません。'); return; }
                if (!confirm(`未取得の ${targets.length} 件を順番に検索します。\n（検索APIの利用料がかかります）よろしいですか？`)) return;
                for (const c of targets) {
                    searchingId.value = c.id;
                    try { await searchClientMedia(c); }
                    catch (e) { console.error(e); }
                }
                searchingId.value = '';
                nextTick(refreshIcons);
            };

            // 期間内の顧客の foundMedia から「ドメイン × 何社」ランキング
            const foundRanking = computed(() => foundDomainsRanking(filteredClientsByDate.value));

            // 当月のAPI使用額
            const monthlyCost = ref(null);

            // ===== 顧客の詳細モーダル =====
            const selectedClient = ref(null);
            const openDetail  = (client) => { selectedClient.value = client; nextTick(refreshIcons); };
            const closeDetail = () => { selectedClient.value = null; };
            // モーダルに出す媒体（不正ドメインは除外）
            const detailMedia = computed(() => {
                const c = selectedClient.value;
                if (!c || !Array.isArray(c.foundMedia)) return [];
                return c.foundMedia.filter(m => m && isValidDomain(m.domain));
            });
            watch(selectedClient, () => nextTick(refreshIcons));

            const exportCsv = () => {
                const label = (dateRangeText.value || '全期間').replace(/ /g, '');
                exportClientsCSV(filteredClientsByDate.value, `受注リスト_${label}.csv`);
            };

            // リスト内容が変わったらアイコンを再描画
            watch(filteredClientsByDate, () => nextTick(refreshIcons));
            onMounted(async () => {
                nextTick(refreshIcons);
                monthlyCost.value = await AppCore.fetchMonthlyCost();
                nextTick(refreshIcons);
            });

            return {
                store, monthlyCost,
                filterStartDate, filterEndDate, resetDateFilter,
                filteredClientsByDate, dateRangeText,
                deleteClient, exportCsv, sourceMedia,
                searchOtherMedia, searchAllPending, searchingId, foundRanking,
                selectedClient, openDetail, closeDetail, detailMedia,
            };
        }
    }).mount('#app');
})();
