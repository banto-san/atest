/**
 * ホーム（TOPページ）の最小 Vue アプリ
 * --------------------------------------------------------------------------
 * 中身（件数・メニュー）はPHPで描画済み。ここは共通ヘッダー（保存インジケーター /
 * CSV出力ボタン）と、当月のOpenAI使用額チップ（このサイト分）を動かすための最小限。
 */
(function () {
    const { createApp, onMounted, nextTick, ref } = Vue;
    const { store, exportClientsCSV, refreshIcons, fetchMonthlyCost } = AppCore;

    createApp({
        setup() {
            // 当月のAPI使用額（このサイト分）
            const monthlyCost = ref(null);

            // ヘッダーのCSVボタン → 全顧客を出力
            const exportCsv = () => exportClientsCSV(store.clients, '全顧客リスト.csv');

            onMounted(async () => {
                nextTick(refreshIcons);
                monthlyCost.value = await fetchMonthlyCost();
                nextTick(refreshIcons);
            });

            return { store, exportCsv, monthlyCost };
        }
    }).mount('#app');
})();
