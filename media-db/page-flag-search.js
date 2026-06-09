/**
 * フラグ(媒体)別 逆引き検索 ページ
 * --------------------------------------------------------------------------
 * リスト元（受注の獲得元媒体）を1つ選ぶと、そのリスト元から受注した顧客を一覧表示。
 * 各顧客を「他媒体を調べる」で検索し、その顧客群のドメイン重複ランキングを表示する。
 */
(function () {
    const { createApp, ref, computed, onMounted, watch, nextTick } = Vue;
    const { store, exportClientsCSV, refreshIcons, searchClientMedia, foundDomainsRanking } = AppCore;

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

            // その顧客群の「他に利用している媒体」ドメイン重複ランキング
            const flaggedRanking = computed(() => foundDomainsRanking(filteredClientsByFlag.value));

            // ===== 他媒体検索 =====
            const searchingId = ref('');

            const searchOtherMedia = async (client) => {
                if (searchingId.value) return;
                searchingId.value = client.id;
                try {
                    await searchClientMedia(client);
                } catch (e) {
                    alert('検索に失敗しました：\n' + ((e && e.message) ? e.message : String(e)));
                } finally {
                    searchingId.value = '';
                    nextTick(refreshIcons);
                }
            };

            const searchAllPending = async () => {
                const targets = filteredClientsByFlag.value.filter(c => !c.searchedAt);
                if (targets.length === 0) { alert('未取得の顧客はありません。'); return; }
                if (!confirm(`未取得の ${targets.length} 件を順番に検索します。\n（検索APIの利用料がかかります）よろしいですか？`)) return;
                for (const c of targets) {
                    searchingId.value = c.id;
                    try { await searchClientMedia(c); } catch (e) { console.error(e); }
                }
                searchingId.value = '';
                nextTick(refreshIcons);
            };

            const exportCsv = () => {
                if (!selectedMediaId.value) {
                    alert('先にリスト元を選択してください。');
                    return;
                }
                exportClientsCSV(filteredClientsByFlag.value, `逆引きリスト_${selectedMediaName.value}.csv`);
            };

            const monthlyCost = ref(null);

            watch([selectedMediaId, filteredClientsByFlag], () => nextTick(refreshIcons));
            onMounted(async () => {
                nextTick(refreshIcons);
                monthlyCost.value = await AppCore.fetchMonthlyCost();
                nextTick(refreshIcons);
            });

            return {
                store, sourceMediaList, monthlyCost,
                selectedMediaId, selectedMediaName, filteredClientsByFlag, flaggedRanking,
                searchOtherMedia, searchAllPending, searchingId, exportCsv,
            };
        }
    }).mount('#app');
})();
