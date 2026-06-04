/**
 * フラグ(媒体)別 逆引き検索 ページ
 * --------------------------------------------------------------------------
 * リスト元（受注の獲得元媒体）を1つ選ぶと、そのリスト元から受注した顧客を一覧表示。
 * 各顧客は seo-hearing で「他に利用している媒体」を検索でき、
 * ドメイン重複ランキングは seo-hearing の画面で確認する。
 */
(function () {
    const { createApp, ref, computed, onMounted, watch, nextTick } = Vue;
    const { store, exportClientsCSV, refreshIcons } = AppCore;

    createApp({
        setup() {
            const selectedMediaId = ref('');

            // ドロップダウンに出すのは「実際にリスト元として使われている媒体」だけ
            const sourceMediaList = computed(() => {
                const ids = new Set(store.clients.map(c => c.sourceMediaId).filter(Boolean));
                return store.media.filter(m => ids.has(m.id));
            });

            const selectedMediaName = computed(() => {
                const m = store.media.find(x => x.id === selectedMediaId.value);
                return m ? m.name : '';
            });

            // 選んだリスト元から受注した顧客
            const filteredClientsByFlag = computed(() => {
                if (!selectedMediaId.value) return [];
                return store.clients.filter(c => c.sourceMediaId === selectedMediaId.value);
            });

            // seo-hearing 連携リンク
            const seoHearingSearch = (client) => AppCore.seoHearing.searchUrl(client.name, client.address);
            const seoHearingRanking = AppCore.seoHearing.rankingUrl();

            const exportCsv = () => {
                if (!selectedMediaId.value) {
                    alert('先にリスト元を選択してください。');
                    return;
                }
                exportClientsCSV(filteredClientsByFlag.value, `逆引きリスト_${selectedMediaName.value}.csv`);
            };

            watch([selectedMediaId, filteredClientsByFlag], () => nextTick(refreshIcons));
            onMounted(() => nextTick(refreshIcons));

            return {
                store, sourceMediaList,
                selectedMediaId, selectedMediaName, filteredClientsByFlag,
                seoHearingSearch, seoHearingRanking, exportCsv,
            };
        }
    }).mount('#app');
})();
