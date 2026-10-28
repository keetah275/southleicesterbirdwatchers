/* global slbBirdLists */
(() => {
    'use strict';
    const config = slbBirdLists;
    const root = document.getElementById('slb-editor');
    const clone = value => JSON.parse(JSON.stringify(value));
    const escape = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[char]));
    let server = clone(config.initial);
    let draft = clone(server.draft);
    let generation = 0;
    let saving = false;
    let publishing = false;
    let review = null;
    let timer;
    let authExpired = false;
    let storageAvailable = true;
    let storageWarning = false;
    const storageKey = `slb-birds:${config.userId}:${config.eventId}`;
    const dirty = () => JSON.stringify(draft) !== JSON.stringify(server.draft) || server.revision === 0;

    root.innerHTML = `
        <p><a href="${escape(config.listUrl)}">← All trip bird lists</a></p>
        <h2>${escape(server.eventTitle)}</h2><p>${escape(server.eventDate)}</p>
        <div id="slb-review" class="slb-review" hidden></div>
        <p id="slb-storage" class="slb-warning" hidden>Browser recovery is unavailable. Keep this screen open until the server confirms your changes are saved.</p>
        <div class="slb-toolbar">
            <label for="slb-search">Find a bird</label>
            <div class="slb-search-row"><input id="slb-search" type="search" placeholder="Search bird names" autocomplete="off"><button id="slb-clear" type="button" class="button">Clear</button></div>
            <div class="slb-status-row"><strong id="slb-count"></strong><button id="slb-save" type="button" class="button">Save now</button></div>
            <p id="slb-status" role="status" aria-live="polite"></p>
            <p id="slb-login" hidden><a href="${escape(config.loginUrl)}" target="_blank" rel="noopener">Sign in in a new tab</a>, then return and reload this screen to recover pending changes.</p>
        </div>
        <label class="slb-filter"><input id="slb-seen-only" type="checkbox"> Show seen birds only</label>
        <p id="slb-filter-status"></p>
        <div id="slb-birds" role="group" aria-label="Full bird checklist"></div>
        <p id="slb-no-results" hidden>No birds match this filter. Clear the search or turn off “seen birds only”.</p>
        <section class="slb-report-settings" aria-label="Report details">
            <h2>Report details</h2>
            <label for="slb-title">Report title</label><input id="slb-title" type="text" maxlength="500">
            <label for="slb-summary">Trip summary (optional)</label><textarea id="slb-summary" rows="5" maxlength="20000"></textarea>
            <p id="slb-publication-status"></p>
            <p id="slb-report-link"></p>
            <button id="slb-preview-button" type="button" class="button button-primary">Preview report</button>
        </section>
        <section id="slb-preview" class="slb-preview" hidden tabindex="-1" aria-label="Report preview">
            <h2>Preview trip report</h2><p>The public report always includes the full checklist, even when you are using a filter above.</p>
            <div id="slb-preview-content"></div>
            <label id="slb-recover-label" class="slb-filter" hidden><input id="slb-recover" type="checkbox"> <span></span></label>
            <p id="slb-publish-status" role="status"></p>
            <button id="slb-publish" type="button" class="button button-primary">Publish trip report</button>
            <button id="slb-close-preview" type="button" class="button">Close preview</button>
        </section>`;
    const el = id => document.getElementById(`slb-${id}`);
    const setStatus = message => { if (el('status').textContent !== message) el('status').textContent = message; };

    function persist() {
        if (!storageAvailable) return;
        try {
            if (dirty()) {
                localStorage.setItem(storageKey, JSON.stringify({revision:server.revision, baseDraft:server.draft, draft}));
            } else {
                localStorage.removeItem(storageKey);
            }
        } catch {
            storageAvailable = false;
            storageWarning = true;
            el('storage').hidden = false;
        }
    }

    function buildBirds() {
        el('birds').innerHTML = draft.species.map((bird, index) => `<label class="slb-bird" data-index="${index}"><input type="checkbox" value="${escape(bird.id)}"><span>${escape(bird.name)}</span><span class="slb-seen-label" aria-hidden="true"></span></label>`).join('');
    }

    function refresh() {
        const selected = new Set(draft.seen);
        const query = el('search').value.trim().toLocaleLowerCase();
        const onlySeen = el('seen-only').checked;
        let visible = 0;
        el('birds').querySelectorAll('.slb-bird').forEach((label, index) => {
            const bird = draft.species[index];
            const isSeen = selected.has(bird.id);
            label.querySelector('input').checked = isSeen;
            label.querySelector('input').disabled = Boolean(review || publishing);
            label.classList.toggle('is-seen', isSeen);
            label.querySelector('.slb-seen-label').textContent = isSeen ? 'Seen' : '';
            label.hidden = !bird.name.toLocaleLowerCase().includes(query) || (onlySeen && !isSeen);
            if (!label.hidden) visible++;
        });
        el('count').textContent = `${selected.size} seen / ${draft.species.length} birds`;
        el('no-results').hidden = visible > 0;
        el('filter-status').textContent = query || onlySeen ? `Filtered view: ${visible} birds shown. The full report still includes every bird.` : `Full checklist: ${draft.species.length} birds.`;
        for (const name of ['title', 'summary', 'preview-button']) el(name).disabled = Boolean(review || publishing);
        el('save').disabled = saving || publishing || Boolean(review) || authExpired || (!dirty());
        el('publish').disabled = saving || publishing || Boolean(review) || authExpired || dirty() || (needsRecovery() && !el('recover').checked);
        const changed = server.unpublishedChanges || dirty();
        el('publication-status').textContent = server.published ? (changed ? 'Unpublished changes. The public report will change only when you update it below.' : 'This checklist matches the published report.') : 'Draft checklist. No report has been published yet.';
        el('report-link').innerHTML = server.reportUrl ? `<a href="${escape(server.reportUrl)}" target="_blank" rel="noopener">View published report</a>` : '';
        el('publish').textContent = publishing ? 'Publishing…' : (server.published ? 'Update published report' : 'Publish trip report');
    }

    function changed() {
        generation++;
        persist();
        setStatus('Unsaved changes');
        el('preview').hidden = true;
        refresh();
        clearTimeout(timer);
        if (!review && !authExpired) timer = setTimeout(save, 800);
    }

    async function request(path, body) {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 20000);
        try {
            const response = await fetch(config.endpoint + path, {
                method:body === undefined ? 'GET' : 'POST', credentials:'same-origin',
                headers:{'Content-Type':'application/json', 'X-WP-Nonce':config.nonce},
                body:body === undefined ? undefined : JSON.stringify(body), signal:controller.signal
            });
            const data = await response.json();
            if (!response.ok) throw {status:response.status, ...data};
            return data;
        } finally {
            clearTimeout(timeout);
        }
    }

    function draftPatch(base, mine, latest) {
        const merged = clone(latest);
        const baseSeen = new Set(base.seen);
        const mineSeen = new Set(mine.seen);
        const latestSeen = new Set(latest.seen);
        for (const bird of latest.species) {
            if (baseSeen.has(bird.id) !== mineSeen.has(bird.id)) {
                if (mineSeen.has(bird.id)) latestSeen.add(bird.id); else latestSeen.delete(bird.id);
            }
        }
        merged.seen = latest.species.filter(b => latestSeen.has(b.id)).map(b => b.id);
        for (const field of ['title','summary']) if (mine[field] !== base[field]) merged[field] = mine[field];
        return merged;
    }

    function showReview(latest, base, mine, reason) {
        clearTimeout(timer);
        review = {latest, base, mine};
        const baseSeen = new Set(base.seen);
        const mineSeen = new Set(mine.seen);
        const latestSeen = new Set(latest.draft.seen);
        const latestIds = new Set(latest.draft.species.map(b => b.id));
        const details = mine.species.filter(b => baseSeen.has(b.id) !== mineSeen.has(b.id)).map(b => latestIds.has(b.id)
            ? `<li>${escape(b.name)}: your edit <strong>${mineSeen.has(b.id) ? 'Seen' : 'Not seen'}</strong>; server <strong>${latestSeen.has(b.id) ? 'Seen' : 'Not seen'}</strong></li>`
            : `<li>${escape(b.name)}: this bird is no longer in the server checklist. Its pending edit cannot be applied.</li>`);
        for (const field of ['title','summary']) {
            if (base[field] !== mine[field]) details.push(`<li>${field === 'title' ? 'Title' : 'Summary'}<br>Your edit: ${escape(mine[field] || '(empty)')}<br>Server: ${escape(latest.draft[field] || '(empty)')}</li>`);
        }
        el('review').innerHTML = `<h2>Review pending changes</h2><p>${escape(reason)}</p><p>The server currently has ${latest.draft.seen.length} sightings. Applying your edits keeps unrelated changes saved by another screen.</p><ul>${details.join('') || '<li>No changed sightings or report details.</li>'}</ul><button type="button" class="button button-primary" id="slb-apply-recovery">Apply my edits to latest version</button> <button type="button" class="button" id="slb-discard-recovery">Use latest server version</button>`;
        el('review').hidden = false;
        el('preview').hidden = true;
        el('apply-recovery').onclick = () => resolveReview(true);
        el('discard-recovery').onclick = () => resolveReview(false);
        setStatus('Review pending changes before saving.');
        refresh();
    }

    function resolveReview(applyMine) {
        const pending = review;
        server = pending.latest;
        draft = applyMine ? draftPatch(pending.base, pending.mine, server.draft) : clone(server.draft);
        review = null;
        el('review').hidden = true;
        el('title').value = draft.title;
        el('summary').value = draft.summary;
        buildBirds();
        generation++;
        persist();
        refresh();
        if (dirty()) { setStatus('Unsaved changes'); save(); }
        else setStatus('Latest server version loaded.');
    }

    function handleFailure(error, action) {
        if (error.code === 'conflict' && error.data?.current) {
            showReview(error.data.current, server.draft, draft, 'Another screen has saved changes since you loaded this checklist.');
        } else if (error.status === 401 || error.status === 403) {
            authExpired = true;
            el('login').hidden = false;
            setStatus('Sign-in expired or access denied. Pending edits have not been saved.');
        } else if (error.status) {
            setStatus(error.message || `${action} failed. Please try again.`);
        } else {
            setStatus('Connection lost — changes not yet saved. Keep this screen open; saving will retry.');
            if (action === 'Save') timer = setTimeout(save, 15000);
        }
        persist();
    }

    async function save() {
        clearTimeout(timer);
        if (saving || publishing || review || authExpired || !dirty()) return;
        saving = true;
        const sentGeneration = generation;
        const sent = clone(draft);
        setStatus('Saving…');
        refresh();
        try {
            const latest = await request('', {revision:server.revision, seen:sent.seen, title:sent.title, summary:sent.summary});
            server = latest;
            if (sentGeneration === generation) {
                draft = clone(latest.draft);
                el('title').value = draft.title;
                el('summary').value = draft.summary;
            }
            persist();
            setStatus(dirty() ? 'Unsaved changes' : `Saved at ${new Date(latest.updatedAt).toLocaleTimeString()}`);
            if (dirty()) timer = setTimeout(save, 250);
        } catch (error) {
            handleFailure(error, 'Save');
        } finally {
            saving = false;
            refresh();
        }
    }

    function needsRecovery() { return ['trash','missing'].includes(server.reportStatus); }

    function preview() {
        const seen = new Set(draft.seen);
        el('preview-content').innerHTML = `<h3>${escape(draft.title)}</h3><p class="slb-summary">${escape(draft.summary)}</p><p><strong>${seen.size} species seen out of ${draft.species.length}</strong></p><p>— means not recorded as seen.</p><table><caption>Complete bird checklist</caption><thead><tr><th scope="col">Bird</th><th scope="col">Sighting</th></tr></thead><tbody>${draft.species.map(bird => `<tr><th scope="row">${escape(bird.name)}</th><td>${seen.has(bird.id) ? '✓ Seen' : '—'}</td></tr>`).join('')}</tbody></table>`;
        el('recover').checked = false;
        el('recover-label').hidden = !needsRecovery();
        el('recover-label').querySelector('span').textContent = server.reportStatus === 'trash' ? 'Restore the existing report from the bin and publish this version.' : 'The original report was deleted. Create a replacement report.';
        el('publish-status').textContent = dirty() ? 'Save your changes before publishing.' : 'Review the full checklist, then publish when ready.';
        el('preview').hidden = false;
        el('preview').focus();
        refresh();
    }

    async function publish() {
        if (dirty() || saving || publishing || review || authExpired || (needsRecovery() && !el('recover').checked)) return;
        publishing = true;
        refresh();
        el('publish-status').textContent = 'Publishing…';
        try {
            server = await request('/publish', {revision:server.revision, recover:el('recover').checked});
            el('publish-status').textContent = 'Report published successfully.';
            setStatus('Checklist saved and report published.');
            persist();
        } catch (error) {
            handleFailure(error, 'Publish');
            el('publish-status').textContent = error.message || 'Publication could not be confirmed. Retry to recover the same report.';
        } finally {
            publishing = false;
            refresh();
        }
    }

    el('birds').addEventListener('change', event => {
        const input = event.target;
        if (!input.matches('input[type="checkbox"]')) return;
        const selected = new Set(draft.seen);
        if (input.checked) selected.add(input.value); else selected.delete(input.value);
        draft.seen = draft.species.filter(b => selected.has(b.id)).map(b => b.id);
        changed();
    });
    for (const name of ['title','summary']) {
        el(name).value = draft[name];
        el(name).addEventListener('input', () => { draft[name] = el(name).value; changed(); });
    }
    el('search').addEventListener('input', refresh);
    el('seen-only').addEventListener('change', refresh);
    el('clear').onclick = () => { el('search').value = ''; el('seen-only').checked = false; refresh(); el('search').focus(); };
    el('save').onclick = save;
    el('preview-button').onclick = preview;
    el('close-preview').onclick = () => { el('preview').hidden = true; el('preview-button').focus(); };
    el('publish').onclick = publish;
    el('recover').onchange = refresh;
    window.addEventListener('online', () => { if (!review && !authExpired) save(); });
    window.addEventListener('beforeunload', event => {
        if (dirty() || saving || publishing || review) { event.preventDefault(); event.returnValue = ''; }
    });
    buildBirds();
    refresh();
    setStatus(server.revision ? 'Saved checklist loaded.' : 'New checklist — save to keep it.');
    try {
        const pending = JSON.parse(localStorage.getItem(storageKey) || 'null');
        if (pending?.draft && pending?.baseDraft && Array.isArray(pending.draft.species) && Array.isArray(pending.draft.seen)) {
            if (JSON.stringify(pending.draft) === JSON.stringify(server.draft)) localStorage.removeItem(storageKey);
            else showReview(server, pending.baseDraft, pending.draft, 'This browser has pending edits from an earlier session. Review them against the current server version.');
        }
    } catch {
        storageAvailable = false;
        storageWarning = true;
    }
    if (storageWarning) el('storage').hidden = false;
})();
