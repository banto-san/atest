/**
 * フラグ(媒体)別 逆引き検索 ページ
 * --------------------------------------------------------------------------
 * 媒体（リスト元）を1つ選ぶと、その媒体を使っている顧客群と、
 * 彼らが「併用している他媒体」の重複ランキングを表示する。
 */
(function () {
    const { createApp, ref, computed, onMounted, watch, nextTick } = Vue;
    const { store, calculateRanking, exportClientsCSV, refreshIcons } = AppCore;

    createApp({
        setup() {
            const mediaList = computed(() => store.media);
            const selectedMediaId = ref('');

            const selectedMediaName = computed(() => {
                const m = store.media.find(x => x.id === selectedMediaId.value);
                return m ? m.name : '';
            });

            // 選択媒体を使っている顧客
            const filteredClientsByFlag = computed(() => {
                if (!selectedMediaId.value) return [];
                return store.clients.filter(c => (c.usedMediaIds || []).includes(selectedMediaId.value));
            });

            // その顧客群の併用媒体ランキング（選択媒体自身は除外）
            const flaggedMediaRanking = computed(() =>
                calculateRanking(filteredClientsByFlag.value).filter(item => item.media.id !== selectedMediaId.value)
            );

            const exportCsv = () => {
                if (!selectedMediaId.value) {
                    alert('先に媒体を選択してください。');
                    return;
                }
                exportClientsCSV(filteredClientsByFlag.value, `逆引きリスト_${selectedMediaName.value}.csv`);
            };

            // 選択や対象顧客が変わるとアイコン（filterアイコン等）が入れ替わるので再描画
            watch([selectedMediaId, filteredClientsByFlag], () => nextTick(refreshIcons));
            onMounted(() => nextTick(refreshIcons));

            return {
                store, mediaList,
                selectedMediaId, selectedMediaName,
                filteredClientsByFlag, flaggedMediaRanking,
                exportCsv,
            };
        }
    }).mount('#app');
})();
