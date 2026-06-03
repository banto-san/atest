/**
 * アカウント管理 ページ
 * --------------------------------------------------------------------------
 * ログインアカウント（表示名 / ログインID / パスワード）の追加・編集・削除。
 */
(function () {
    const { createApp, ref, computed, onMounted, watch, nextTick } = Vue;
    const { store, exportClientsCSV, refreshIcons } = AppCore;

    const genId = (prefix) => prefix + Date.now() + Math.floor(Math.random() * 100000);

    createApp({
        setup() {
            const systemUsers = computed(() => store.users);

            const userForm = ref({ name: '', loginId: '', password: '' });
            const editingUser = ref(null);

            const saveUser = () => {
                if (editingUser.value) {
                    const index = store.users.findIndex(u => u.id === editingUser.value.id);
                    if (index !== -1) {
                        store.users[index] = { ...store.users[index], ...userForm.value };
                    }
                } else {
                    store.users.push({ id: genId('u'), ...userForm.value });
                }
                userForm.value = { name: '', loginId: '', password: '' };
                editingUser.value = null;
            };

            const editUser = (user) => {
                editingUser.value = user;
                userForm.value = { name: user.name, loginId: user.loginId, password: user.password };
            };

            const cancelUserEdit = () => {
                editingUser.value = null;
                userForm.value = { name: '', loginId: '', password: '' };
            };

            const deleteUser = (id) => {
                if (store.users.length > 1) {
                    store.users = store.users.filter(u => u.id !== id);
                }
            };

            // このページは顧客リストを直接扱わないが、ヘッダーのCSVボタンは全顧客を出力
            const exportCsv = () => exportClientsCSV(store.clients, '全顧客リスト.csv');

            // 行の増減・編集状態でアイコン（編集/削除）が入れ替わるので再描画
            watch([() => store.users.length, editingUser], () => nextTick(refreshIcons));
            onMounted(() => nextTick(refreshIcons));

            return {
                store, systemUsers,
                userForm, editingUser, saveUser, editUser, cancelUserEdit, deleteUser,
                exportCsv,
            };
        }
    }).mount('#app');
})();
