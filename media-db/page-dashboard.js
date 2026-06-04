/**
 * 受注一覧・ランキング ページ
 * --------------------------------------------------------------------------
 * 期間で受注顧客を絞り込み、一覧と「併用媒体ランキング」を表示する。
 */
(function () {
    const { createApp, ref, computed, onMounted, watch, nextTick } = Vue;
    const { store, exportClientsCSV, refreshIcons } = AppCore;

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

            // seo-hearing 連携リンク（会社名＋住所で検索 / 重複ランキング画面）
            const seoHearingSearch = (client) => AppCore.seoHearing.searchUrl(client.name, client.address);
            const seoHearingRanking = AppCore.seoHearing.rankingUrl();

            const exportCsv = () => {
                const label = (dateRangeText.value || '全期間').replace(/ /g, '');
                exportClientsCSV(filteredClientsByDate.value, `受注リスト_${label}.csv`);
            };

            // リスト内容が変わったらアイコンを再描画
            watch(filteredClientsByDate, () => nextTick(refreshIcons));
            onMounted(() => nextTick(refreshIcons));

            return {
                store,
                filterStartDate, filterEndDate, resetDateFilter,
                filteredClientsByDate, dateRangeText,
                deleteClient, exportCsv, sourceMedia,
                seoHearingSearch, seoHearingRanking,
            };
        }
    }).mount('#app');
})();
