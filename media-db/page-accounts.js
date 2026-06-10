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

            const userForm = ref({ name: '', loginId: '', password: '', role: 'member' });
            const editingUser = ref(null);

            const adminCount = () => store.users.filter(u => (u.role || 'member') === 'admin').length;

            const saveUser = () => {
                const f = userForm.value;
                if (!f.name.trim() || !f.loginId.trim()) { alert('表示名とログインIDを入力してください。'); return; }
                if (editingUser.value) {
                    const index = store.users.findIndex(u => u.id === editingUser.value.id);
                    if (index !== -1) {
                        // 最後の管理者(全権限)を降格しようとした場合は防ぐ
                        if (store.users[index].role === 'admin' && f.role !== 'admin' && adminCount() <= 1) {
                            alert('最後の管理者（全権限）を変更することはできません。先に別の管理者を作成してください。');
                            return;
                        }
                        const updated = { ...store.users[index], name: f.name, loginId: f.loginId, role: f.role };
                        if (f.password.trim() !== '') updated.password = f.password;   // 空欄なら現在のパスワードを維持
                        store.users[index] = updated;
                    }
                } else {
                    // 新規：パスワードは任意（空ならGoogleログイン専用アカウント）
                    store.users.push({ id: genId('u'), name: f.name, loginId: f.loginId, password: f.password || '', role: f.role });
                }
                userForm.value = { name: '', loginId: '', password: '', role: 'member' };
                editingUser.value = null;
            };

            // 権限の表示ラベル / バッジ色（3段階）
            const roleLabel = (r) => ({ admin: '管理者（全権限）', manager: 'API利用可', member: '閲覧のみ' })[r] || '閲覧のみ';
            const roleBadge = (r) => ({ admin: 'bg-blue-100 text-blue-700', manager: 'bg-emerald-100 text-emerald-700', member: 'bg-gray-100 text-gray-600' })[r] || 'bg-gray-100 text-gray-600';

            const editUser = (user) => {
                editingUser.value = user;
                userForm.value = { name: user.name, loginId: user.loginId, password: user.password, role: user.role || 'member' };
            };

            const cancelUserEdit = () => {
                editingUser.value = null;
                userForm.value = { name: '', loginId: '', password: '', role: 'member' };
            };

            const deleteUser = (id) => {
                const target = store.users.find(u => u.id === id);
                if (!target) return;
                if (store.users.length <= 1) return;
                if ((target.role || 'member') === 'admin' && adminCount() <= 1) {
                    alert('最後の管理者は削除できません。先に別の管理者を作成してください。');
                    return;
                }
                if (!confirm(`「${target.name}」を削除しますか？`)) return;
                store.users = store.users.filter(u => u.id !== id);
            };

            // このページは顧客リストを直接扱わないが、ヘッダーのCSVボタンは全顧客を出力
            const exportCsv = () => exportClientsCSV(store.clients, '全顧客リスト.csv');

            // 行の増減・編集状態でアイコン（編集/削除）が入れ替わるので再描画
            watch([() => store.users.length, editingUser], () => nextTick(refreshIcons));
            onMounted(() => nextTick(refreshIcons));

            return {
                store, systemUsers,
                userForm, editingUser, saveUser, editUser, cancelUserEdit, deleteUser,
                roleLabel, roleBadge,
                exportCsv,
            };
        }
    }).mount('#app');
})();
