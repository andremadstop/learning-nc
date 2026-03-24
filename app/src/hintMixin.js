export default {
	methods: {
		hintDismissed(id) {
			try { return localStorage.getItem('learning-hint-' + id + '-dismissed') === '1'; }
			catch { return false; }
		},
		dismissHint(id) {
			try { localStorage.setItem('learning-hint-' + id + '-dismissed', '1'); }
			catch (_e) { /* intentional */ }
			this.$forceUpdate();
		},
	},
};
