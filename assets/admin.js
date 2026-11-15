(() => {
	'use strict';

	const form = document.getElementById('jmo-test-connection-form');
	if (!form || typeof window.jmoConnectionTest === 'undefined') {
		return;
	}

	const button = document.getElementById('jmo-test-connection-button');
	const feedback = document.getElementById('jmo-connection-feedback');
	const strings = window.jmoConnectionTest.strings;
	const originalLabel = button.textContent;
	let requestInFlight = false;

	function renderFeedback(kind, heading, message, testedAt) {
		const noticeClass = {
			success: 'notice-success',
			error: 'notice-error',
			warning: 'notice-warning',
			info: 'notice-info'
		}[kind] || 'notice-info';
		const paragraph = document.createElement('p');
		const title = document.createElement('strong');
		title.textContent = heading;
		paragraph.appendChild(title);
		paragraph.appendChild(document.createElement('br'));
		const detail = document.createElement('span');
		detail.className = 'jmo-connection-message';
		detail.textContent = message;
		paragraph.appendChild(detail);

		if (testedAt) {
			paragraph.appendChild(document.createElement('br'));
			const time = document.createElement('span');
			time.className = 'description';
			time.textContent = strings.lastTested + ' ' + new Date(testedAt).toLocaleString();
			paragraph.appendChild(time);
		}

		feedback.className = 'notice ' + noticeClass + ' inline';
		feedback.setAttribute('role', kind === 'error' ? 'alert' : 'status');
		feedback.setAttribute('aria-live', kind === 'error' ? 'assertive' : 'polite');
		feedback.setAttribute('aria-atomic', 'true');
		feedback.replaceChildren(paragraph);
	}

	form.addEventListener('submit', async (event) => {
		event.preventDefault();
		if (requestInFlight) {
			return;
		}

		requestInFlight = true;
		button.disabled = true;
		button.setAttribute('aria-busy', 'true');
		button.textContent = strings.testing;
		renderFeedback('info', strings.testing, strings.checking, '');

		try {
			const response = await fetch(window.jmoConnectionTest.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: new URLSearchParams(new FormData(form)).toString()
			});
			let payload;
			try {
				payload = await response.json();
			} catch (error) {
				throw new Error('unexpected_response');
			}

			const result = payload && payload.data;
			if (result && result.code === 'invalid_nonce') {
				renderFeedback('error', strings.failed, strings.expired, '');
				return;
			}
			if (result && result.code === 'forbidden') {
				renderFeedback('error', strings.failed, strings.unauthorized, '');
				return;
			}
			if (!result || typeof result.status !== 'string' || typeof result.message !== 'string') {
				throw new Error('unexpected_response');
			}
			if (payload.success && result.status === 'connected' && result.stale === false) {
				renderFeedback('success', strings.success, result.message, result.tested_at);
				return;
			}
			renderFeedback('error', strings.failed, result.message, result.tested_at || '');
		} catch (error) {
			const message = error && error.message === 'unexpected_response'
				? strings.unexpected
				: strings.network;
			renderFeedback('error', strings.failed, message, '');
		} finally {
			requestInFlight = false;
			button.disabled = false;
			button.removeAttribute('aria-busy');
			button.textContent = originalLabel;
		}
	});
})();
