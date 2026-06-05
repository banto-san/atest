/**
 * フラグ(媒体)別 逆引き検索 ページ
 * --------------------------------------------------------------------------
 * リスト元（受注の獲得元媒体）を1つ選ぶと、そのリスト元から受注した顧客を一覧表示。
 * 各顧客は「他媒体を調べる」で検索でき、ドメイン重複ランキングも将来このサイトで集計する。
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

            // 他媒体検索（このサイト自身で検索する仕組みは検索API接続後に有効化）
            const searchOtherMedia = (client) => {
                alert('他媒体の検索機能は準備中です（検索API接続後に有効になります）。');
            };

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
                searchOtherMedia, exportCsv,
            };
        }
    }).mount('#app');
})();
