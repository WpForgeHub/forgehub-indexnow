(function () {
	'use strict';

	if (typeof window.wpfhInAdmin === 'undefined') {
		return;
	}

	var cfg = window.wpfhInAdmin;

	function postAjax(action, extra) {
		var body = new URLSearchParams();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		Object.keys(extra || {}).forEach(function (k) {
			body.append(k, extra[k]);
		});

		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (res) {
			return res.json();
		});
	}

	function setStatus(el, ok, text) {
		if (!el) {
			return;
		}
		el.textContent = text;
		el.classList.remove('wpfh-in-status-ok', 'wpfh-in-status-err');
		el.classList.add(ok ? 'wpfh-in-status-ok' : 'wpfh-in-status-err');
	}

	document.addEventListener('DOMContentLoaded', function () {
		var verifyBtn = document.getElementById('wpfh-in-verify-key');
		var verifyStatus = document.getElementById('wpfh-in-verify-status');
		if (verifyBtn) {
			verifyBtn.addEventListener('click', function () {
				verifyBtn.disabled = true;
				setStatus(verifyStatus, true, cfg.i18n.verifying);
				postAjax('wpfh_in_verify_key')
					.then(function (res) {
						setStatus(verifyStatus, !!res.success, (res.data && res.data.message) || cfg.i18n.networkError);
					})
					.catch(function () {
						setStatus(verifyStatus, false, cfg.i18n.networkError);
					})
					.finally(function () {
						verifyBtn.disabled = false;
					});
			});
		}

		var regenBtn = document.getElementById('wpfh-in-regen-key');
		var keyInput = document.getElementById('wpfh-in-api-key');
		var keyLink = document.getElementById('wpfh-in-key-link');
		if (regenBtn) {
			regenBtn.addEventListener('click', function () {
				if (!window.confirm(cfg.i18n.confirmRegen)) {
					return;
				}
				regenBtn.disabled = true;
				setStatus(verifyStatus, true, cfg.i18n.regenerating);
				postAjax('wpfh_in_regenerate_key')
					.then(function (res) {
						if (res.success && res.data) {
							if (keyInput) {
								keyInput.value = res.data.api_key;
							}
							if (keyLink) {
								keyLink.href = res.data.key_location;
								keyLink.textContent = res.data.key_location;
							}
							setStatus(verifyStatus, true, res.data.message);
						} else {
							setStatus(verifyStatus, false, (res.data && res.data.message) || cfg.i18n.networkError);
						}
					})
					.catch(function () {
						setStatus(verifyStatus, false, cfg.i18n.networkError);
					})
					.finally(function () {
						regenBtn.disabled = false;
					});
			});
		}

		var manualBtn = document.getElementById('wpfh-in-manual-submit');
		var manualUrl = document.getElementById('wpfh-in-manual-url');
		var manualResult = document.getElementById('wpfh-in-manual-result');
		if (manualBtn && manualUrl) {
			manualBtn.addEventListener('click', function () {
				var val = manualUrl.value.trim();
				if (!val) {
					setStatus(manualResult, false, cfg.i18n.emptyUrl);
					return;
				}
				manualBtn.disabled = true;
				setStatus(manualResult, true, cfg.i18n.sending);
				postAjax('wpfh_in_manual_ping', { url: val })
					.then(function (res) {
						var msg = (res.data && res.data.message) || cfg.i18n.networkError;
						if (res.data && res.data.status) {
							msg = 'HTTP ' + res.data.status + ': ' + msg;
						}
						setStatus(manualResult, !!res.success, msg);
					})
					.catch(function () {
						setStatus(manualResult, false, cfg.i18n.networkError);
					})
					.finally(function () {
						manualBtn.disabled = false;
					});
			});
		}

		var clearBtn = document.getElementById('wpfh-in-clear-logs');
		if (clearBtn) {
			clearBtn.addEventListener('click', function () {
				if (!window.confirm(cfg.i18n.confirmClear)) {
					return;
				}
				clearBtn.disabled = true;
				postAjax('wpfh_in_clear_logs')
					.then(function (res) {
						if (res.success) {
							window.location.reload();
						}
					})
					.finally(function () {
						clearBtn.disabled = false;
					});
			});
		}

		var bulkBtn = document.getElementById('wpfh-in-bulk-submit');
		var bulkRecentBtn = document.getElementById('wpfh-in-bulk-recent');
		var bulkTextarea = document.getElementById('wpfh-in-bulk-urls');
		var bulkResult = document.getElementById('wpfh-in-bulk-result');
		var retryBtn = document.getElementById('wpfh-in-run-retries');

		if (bulkBtn && bulkTextarea) {
			bulkBtn.addEventListener('click', function () {
				bulkBtn.disabled = true;
				setStatus(bulkResult, true, cfg.i18n.sending);
				postAjax('wpfh_in_pro_bulk_submit', { mode: 'textarea', urls: bulkTextarea.value })
					.then(function (res) {
						setStatus(bulkResult, !!res.success, (res.data && res.data.message) || cfg.i18n.networkError);
					})
					.catch(function () {
						setStatus(bulkResult, false, cfg.i18n.networkError);
					})
					.finally(function () {
						bulkBtn.disabled = false;
					});
			});
		}

		if (bulkRecentBtn) {
			bulkRecentBtn.addEventListener('click', function () {
				bulkRecentBtn.disabled = true;
				setStatus(bulkResult, true, cfg.i18n.sending);
				postAjax('wpfh_in_pro_bulk_submit', { mode: 'recent' })
					.then(function (res) {
						setStatus(bulkResult, !!res.success, (res.data && res.data.message) || cfg.i18n.networkError);
					})
					.catch(function () {
						setStatus(bulkResult, false, cfg.i18n.networkError);
					})
					.finally(function () {
						bulkRecentBtn.disabled = false;
					});
			});
		}

		if (retryBtn) {
			retryBtn.addEventListener('click', function () {
				retryBtn.disabled = true;
				setStatus(bulkResult, true, cfg.i18n.sending);
				postAjax('wpfh_in_pro_run_retries')
					.then(function (res) {
						setStatus(bulkResult, !!res.success, (res.data && res.data.message) || cfg.i18n.networkError);
					})
					.catch(function () {
						setStatus(bulkResult, false, cfg.i18n.networkError);
					})
					.finally(function () {
						retryBtn.disabled = false;
					});
			});
		}
	});
})();
