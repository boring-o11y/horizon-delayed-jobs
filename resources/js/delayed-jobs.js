/**
 * The Retries page for the Horizon dashboard.
 *
 * Horizon's dashboard is a compiled Vue bundle with no extension point, so this
 * runs alongside it as plain JavaScript. It renders into the mount the server
 * spliced in after Horizon's router outlet, on a path Horizon's own router does
 * not know: there, Horizon renders an empty outlet and this page is the only
 * content in the column.
 */
(function () {
    const settings = window.HorizonDelayedJobs;

    if (!settings) {
        return;
    }

    const state = {
        type: 'retries',
        queue: '',
        search: '',
        page: 1,
        selection: new Set(),
        jobs: [],
        meta: null,
        error: null,
        loading: false,
    };

    let pollTimer = null;
    let tickTimer = null;
    let searchTimer = null;
    let mounted = false;

    /* ------------------------------------------------------------- helpers */

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function csrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');

        return meta ? meta.getAttribute('content') : null;
    }

    function mount() {
        return document.getElementById(settings.pageId);
    }

    function onPage() {
        return window.location.pathname.replace(/\/$/, '') === settings.pagePath.replace(/\/$/, '');
    }

    /**
     * Format a countdown the way the dashboard talks about time elsewhere.
     */
    function humanize(seconds) {
        if (seconds <= 0) {
            return 'due now';
        }

        if (seconds < 60) {
            return 'in ' + seconds + 's';
        }

        if (seconds < 3600) {
            return 'in ' + Math.floor(seconds / 60) + 'm ' + (seconds % 60) + 's';
        }

        const hours = Math.floor(seconds / 3600);

        if (hours < 24) {
            return 'in ' + hours + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
        }

        return 'in ' + Math.floor(hours / 24) + 'd ' + (hours % 24) + 'h';
    }

    function attempts(job) {
        return job.max_tries ? job.attempts + ' / ' + job.max_tries : String(job.attempts);
    }

    /* ------------------------------------------------------------ requests */

    function request(url, options) {
        const headers = Object.assign({
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        }, (options || {}).headers || {});

        const token = csrfToken();

        if (token) {
            headers['X-CSRF-TOKEN'] = token;
        }

        return fetch(url, Object.assign({credentials: 'same-origin'}, options || {}, {headers}));
    }

    function load() {
        const query = new URLSearchParams({
            type: state.type,
            queue: state.queue,
            search: state.search,
            page: String(state.page),
            per_page: String(settings.perPage),
        });

        state.loading = true;

        return request(settings.indexUrl + '?' + query.toString())
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Request failed with status ' + response.status);
                }

                return response.json();
            })
            .then((data) => {
                state.jobs = data.jobs || [];
                state.meta = data;
                state.error = null;

                // Drop selections for jobs that are no longer listed, so a bulk
                // action can never act on something the page stopped showing.
                const visible = new Set(state.jobs.map((job) => job.id));
                state.selection.forEach((id) => visible.has(id) || state.selection.delete(id));
            })
            .catch((error) => {
                state.error = error.message || 'Could not load delayed jobs.';
            })
            .finally(() => {
                state.loading = false;
                render();
            });
    }

    function perform(ids) {
        if (!settings.performNow || ids.length === 0) {
            return Promise.resolve();
        }

        const single = ids.length === 1;

        const url = single
            ? settings.performUrl + '/' + encodeURIComponent(ids[0])
            : settings.performUrl;

        const job = single ? state.jobs.find((candidate) => candidate.id === ids[0]) : null;

        return request(url, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(single
                ? {connection: job ? job.connection : null, queue: job ? job.queue : null}
                : {ids: ids}),
        })
            .then((response) => {
                if (response.status === 404) {
                    state.error = 'That job is no longer waiting on a delay - it may have already been picked up.';
                }
            })
            .catch((error) => {
                state.error = error.message || 'Could not run that job now.';
            })
            .finally(() => {
                ids.forEach((id) => state.selection.delete(id));

                return load();
            });
    }

    /* ------------------------------------------------------------ rendering */

    function shell() {
        const performButton = settings.performNow
            ? '<button type="button" class="btn btn-primary btn-sm" data-hdj-perform-selected disabled>Run selected now</button>'
            : '';

        return [
            '<div class="card mb-4" data-hdj-card>',
            '  <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">',
            '    <h5 class="mb-0">' + escapeHtml(settings.label) + '</h5>',
            '    <div class="d-flex align-items-center gap-2 flex-wrap">',
            '      <div class="btn-group btn-group-sm" role="group">',
            '        <button type="button" class="btn btn-outline-secondary" data-hdj-type="retries">Retries</button>',
            '        <button type="button" class="btn btn-outline-secondary" data-hdj-type="scheduled">Scheduled</button>',
            '        <button type="button" class="btn btn-outline-secondary" data-hdj-type="">All</button>',
            '      </div>',
            '      <select class="form-select form-select-sm" data-hdj-queue style="width:auto"></select>',
            '      <input type="search" class="form-control form-control-sm" data-hdj-search placeholder="Filter by job name" style="width:14rem">',
            '      ' + performButton,
            '    </div>',
            '  </div>',
            '  <div data-hdj-notice></div>',
            '  <div class="table-responsive">',
            '    <table class="table table-hover mb-0">',
            '      <thead>',
            '        <tr>',
            (settings.performNow ? '<th style="width:2.5rem"><input type="checkbox" data-hdj-select-all></th>' : ''),
            '          <th>Job</th>',
            '          <th>Queue</th>',
            '          <th>Attempts</th>',
            '          <th>Runs</th>',
            (settings.performNow ? '<th style="width:8rem"></th>' : ''),
            '        </tr>',
            '      </thead>',
            '      <tbody data-hdj-rows></tbody>',
            '    </table>',
            '  </div>',
            '  <div class="card-footer d-flex align-items-center justify-content-between" data-hdj-footer></div>',
            '</div>',
        ].join('');
    }

    function render() {
        const root = mount();

        if (!root) {
            return;
        }

        if (!mounted) {
            root.innerHTML = shell();
            mounted = true;
        }

        renderControls(root);
        renderNotice(root);
        renderRows(root);
        renderFooter(root);
    }

    function renderControls(root) {
        root.querySelectorAll('[data-hdj-type]').forEach((button) => {
            button.classList.toggle('active', button.getAttribute('data-hdj-type') === state.type);
        });

        const select = root.querySelector('[data-hdj-queue]');
        const queues = (state.meta && state.meta.queues) || [];

        // Rebuilt only when the queue list actually changed, so the dropdown
        // does not close under someone mid-choice on every poll.
        const signature = queues.join(' ');

        if (select && select.getAttribute('data-hdj-signature') !== signature) {
            select.setAttribute('data-hdj-signature', signature);
            select.innerHTML = ['<option value="">All queues</option>']
                .concat(queues.map((queue) => '<option value="' + escapeHtml(queue) + '">' + escapeHtml(queue) + '</option>'))
                .join('');
        }

        if (select && select.value !== state.queue) {
            select.value = state.queue;
        }

        const selected = root.querySelector('[data-hdj-perform-selected]');

        if (selected) {
            selected.disabled = state.selection.size === 0;
            selected.textContent = state.selection.size === 0
                ? 'Run selected now'
                : 'Run ' + state.selection.size + ' now';
        }
    }

    function renderNotice(root) {
        const notice = root.querySelector('[data-hdj-notice]');

        if (!notice) {
            return;
        }

        if (state.error) {
            notice.innerHTML = '<div class="alert alert-danger rounded-0 mb-0">' + escapeHtml(state.error) + '</div>';

            return;
        }

        if (state.meta && state.meta.truncated) {
            notice.innerHTML = '<div class="alert alert-warning rounded-0 mb-0">'
                + 'More jobs are delayed than this page reads at once, so the list below is a partial view. '
                + 'Raise <code>horizon-delayed-jobs.scan_limit</code> to widen it.'
                + '</div>';

            return;
        }

        notice.innerHTML = '';
    }

    function renderRows(root) {
        const body = root.querySelector('[data-hdj-rows]');

        if (!body) {
            return;
        }

        const columns = settings.performNow ? 6 : 4;

        if (state.jobs.length === 0) {
            body.innerHTML = '<tr><td colspan="' + columns + '" class="text-center text-muted py-4">'
                + (state.loading && !state.meta ? 'Loading...' : 'No jobs are waiting on a delay.')
                + '</td></tr>';

            return;
        }

        body.innerHTML = state.jobs.map((job) => {
            const checkbox = settings.performNow
                ? '<td><input type="checkbox" data-hdj-select="' + escapeHtml(job.id) + '"'
                    + (state.selection.has(job.id) ? ' checked' : '') + '></td>'
                : '';

            const button = settings.performNow
                ? '<td class="text-end"><button type="button" class="btn btn-sm btn-outline-primary" data-hdj-perform="'
                    + escapeHtml(job.id) + '">Run now</button></td>'
                : '';

            return '<tr>'
                + checkbox
                + '<td><span class="fw-medium">' + escapeHtml(job.name) + '</span>'
                + '<br><small class="text-muted">' + escapeHtml(job.id) + '</small></td>'
                + '<td>' + escapeHtml(job.queue) + '</td>'
                + '<td>' + escapeHtml(attempts(job)) + '</td>'
                + '<td><span data-hdj-countdown="' + escapeHtml(job.id) + '" data-hdj-seconds="'
                + escapeHtml(job.seconds_remaining) + '">' + escapeHtml(humanize(job.seconds_remaining)) + '</span></td>'
                + button
                + '</tr>';
        }).join('');

        const all = root.querySelector('[data-hdj-select-all]');

        if (all) {
            all.checked = state.jobs.length > 0 && state.jobs.every((job) => state.selection.has(job.id));
        }
    }

    function renderFooter(root) {
        const footer = root.querySelector('[data-hdj-footer]');

        if (!footer) {
            return;
        }

        const meta = state.meta || {matching: 0, total: 0, page: 1, per_page: settings.perPage};
        const from = meta.matching === 0 ? 0 : (meta.page - 1) * meta.per_page + 1;
        const to = Math.min(meta.page * meta.per_page, meta.matching);
        const last = Math.max(1, Math.ceil(meta.matching / meta.per_page));

        footer.innerHTML = '<small class="text-muted">Showing ' + from + '-' + to + ' of ' + meta.matching
            + ' matching, ' + meta.total + ' delayed in total</small>'
            + '<div class="btn-group btn-group-sm">'
            + '<button type="button" class="btn btn-outline-secondary" data-hdj-page="prev"'
            + (meta.page <= 1 ? ' disabled' : '') + '>Previous</button>'
            + '<button type="button" class="btn btn-outline-secondary" data-hdj-page="next"'
            + (meta.page >= last ? ' disabled' : '') + '>Next</button>'
            + '</div>';
    }

    /**
     * Count the visible rows down locally.
     *
     * The remaining seconds come from the server so a clock that disagrees with
     * the queue's cannot make a job look due early; between polls they are just
     * decremented here.
     */
    function tick() {
        const root = mount();

        if (!root) {
            return;
        }

        root.querySelectorAll('[data-hdj-countdown]').forEach((element) => {
            const seconds = Math.max(0, parseInt(element.getAttribute('data-hdj-seconds'), 10) - 1);

            element.setAttribute('data-hdj-seconds', String(seconds));
            element.textContent = humanize(seconds);
        });
    }

    /* -------------------------------------------------------------- events */

    function within(target) {
        const root = mount();

        return root && target && target.closest && root.contains(target);
    }

    document.addEventListener('click', (event) => {
        const target = event.target;

        if (!within(target)) {
            return;
        }

        const type = target.closest('[data-hdj-type]');

        if (type) {
            state.type = type.getAttribute('data-hdj-type');
            state.page = 1;
            load();

            return;
        }

        const page = target.closest('[data-hdj-page]');

        if (page) {
            state.page = Math.max(1, state.page + (page.getAttribute('data-hdj-page') === 'next' ? 1 : -1));
            load();

            return;
        }

        const single = target.closest('[data-hdj-perform]');

        if (single) {
            single.disabled = true;
            perform([single.getAttribute('data-hdj-perform')]);

            return;
        }

        if (target.closest('[data-hdj-perform-selected]')) {
            perform(Array.from(state.selection));

            return;
        }

        const all = target.closest('[data-hdj-select-all]');

        if (all) {
            state.jobs.forEach((job) => all.checked
                ? state.selection.add(job.id)
                : state.selection.delete(job.id));

            render();

            return;
        }

        const select = target.closest('[data-hdj-select]');

        if (select) {
            const id = select.getAttribute('data-hdj-select');

            select.checked ? state.selection.add(id) : state.selection.delete(id);

            render();
        }
    });

    document.addEventListener('input', (event) => {
        const target = event.target;

        if (!within(target) || !target.closest('[data-hdj-search]')) {
            return;
        }

        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            state.search = target.value;
            state.page = 1;
            load();
        }, 250);
    });

    document.addEventListener('change', (event) => {
        const target = event.target;

        if (!within(target) || !target.closest('[data-hdj-queue]')) {
            return;
        }

        state.queue = target.value;
        state.page = 1;
        load();
    });

    /* ----------------------------------------------------------- lifecycle */

    function start() {
        stop();
        load();
        pollTimer = setInterval(load, settings.pollInterval);
        tickTimer = setInterval(tick, 1000);
    }

    function stop() {
        clearInterval(pollTimer);
        clearInterval(tickTimer);
        pollTimer = null;
        tickTimer = null;
    }

    function sync() {
        const root = mount();

        if (!root) {
            return;
        }

        if (!onPage()) {
            stop();
            mounted = false;
            root.innerHTML = '';

            return;
        }

        if (pollTimer === null) {
            start();
        }
    }

    /**
     * Horizon navigates with the History API, which fires no event of its own,
     * so pushState is wrapped to announce itself. Without this the page would
     * only appear on a full load.
     */
    ['pushState', 'replaceState'].forEach((method) => {
        const original = history[method];

        history[method] = function () {
            const result = original.apply(this, arguments);

            window.dispatchEvent(new Event('hdj:navigated'));

            return result;
        };
    });

    window.addEventListener('popstate', sync);
    window.addEventListener('hdj:navigated', sync);

    document.readyState === 'loading'
        ? document.addEventListener('DOMContentLoaded', sync)
        : sync();
})();
