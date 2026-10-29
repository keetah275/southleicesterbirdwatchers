/* global slbBirdLists */
(() => {
    'use strict';
    const config = slbBirdLists;
    const root = document.getElementById('slb-editor');
    const clone = value => JSON.parse(JSON.stringify(value));
    const normalise = value => ({...value, heard:value.heard || []});
    const escape = value => String(value).replace(/[&<>"']/g, char => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[char]));
    let server = clone(config.initial);
    server.draft = normalise(server.draft);
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
            <button id="slb-jump-bottom" type="button" class="button slb-jump">↓ Report details</button>
        </div>
        <label class="slb-filter"><input id="slb-seen-only" type="checkbox"> Show recorded birds only</label>
        <p id="slb-filter-status"></p>
        <div id="slb-birds" role="group" aria-label="Full bird checklist"></div>
        <p id="slb-no-results" hidden>No birds match this filter. Clear the search or turn off “recorded birds only”.</p>
        <section class="slb-report-settings" aria-label="Report details">
            <h2 id="slb-report-heading" tabindex="-1">Report details</h2>
            <p><button id="slb-jump-top" type="button" class="button">↑ Back to checklist</button></p>
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
        el('birds').innerHTML = draft.species.map((bird, index) => `<div class="slb-bird" data-index="${index}"><span>${escape(bird.name)}</span>${["seen","heard"].map(kind => `<label class="slb-observation"><input type="checkbox" data-kind="${kind}" value="${escape(bird.id)}" aria-label="${kind === "seen" ? "Seen" : "Heard"}: ${escape(bird.name)}"><span>${kind === "seen" ? "Seen" : "Heard"}</span></label>`).join('')}</div>`).join('');
    }

    function refresh() {
        const selected = new Set(draft.seen);
        const heard = new Set(draft.heard);
        const query = el('search').value.trim().toLocaleLowerCase();
        const onlySeen = el('seen-only').checked;
        let visible = 0;
        el('birds').querySelectorAll('.slb-bird').forEach((label, index) => {
            const bird = draft.species[index];
            const isSeen = selected.has(bird.id);
            for (const kind of ['seen','heard']) {
                const input = label.querySelector(`[data-kind="${kind}"]`);
                input.checked = kind === 'seen' ? isSeen : heard.has(bird.id);
                input.disabled = Boolean(review || publishing);
            }
            label.classList.toggle('is-seen', isSeen);
            label.classList.toggle('is-heard', heard.has(bird.id));
            label.hidden = !bird.name.toLocaleLowerCase().includes(query) || (onlySeen && !isSeen && !heard.has(bird.id));
            if (!label.hidden) visible++;
        });
        el('count').textContent = `${selected.size} seen · ${heard.size} heard / ${draft.species.length} birds`;
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
        for (const kind of ['seen','heard']) {
            const baseSet = new Set(base[kind] || []);
            const mineSet = new Set(mine[kind] || []);
            const latestSet = new Set(latest[kind] || []);
            for (const bird of latest.species) {
                if (baseSet.has(bird.id) !== mineSet.has(bird.id)) {
                    if (mineSet.has(bird.id)) latestSet.add(bird.id); else latestSet.delete(bird.id);
                }
            }
            merged[kind] = latest.species.filter(b => latestSet.has(b.id)).map(b => b.id);
        }
        for (const field of ['title','summary']) if (mine[field] !== base[field]) merged[field] = mine[field];
        return merged;
    }

    function showReview(latest, base, mine, reason) {
        clearTimeout(timer);
        review = {latest, base, mine};
        const latestIds = new Set(latest.draft.species.map(b => b.id));
        const details = [];
        for (const kind of ['seen','heard']) {
            const baseSet = new Set(base[kind] || []);
            const mineSet = new Set(mine[kind] || []);
            const latestSet = new Set(latest.draft[kind] || []);
            for (const bird of mine.species.filter(b => baseSet.has(b.id) !== mineSet.has(b.id))) {
                details.push(latestIds.has(bird.id)
                    ? `<li>${escape(bird.name)} (${kind}): your edit <strong>${mineSet.has(bird.id) ? 'Yes' : 'No'}</strong>; server <strong>${latestSet.has(bird.id) ? 'Yes' : 'No'}</strong></li>`
                    : `<li>${escape(bird.name)}: this bird is no longer in the server checklist. Its pending edit cannot be applied.</li>`);
            }
        }
        for (const field of ['title','summary']) {
            if (base[field] !== mine[field]) details.push(`<li>${field === 'title' ? 'Title' : 'Summary'}<br>Your edit: ${escape(mine[field] || '(empty)')}<br>Server: ${escape(latest.draft[field] || '(empty)')}</li>`);
        }
        el('review').innerHTML = `<h2>Review pending changes</h2><p>${escape(reason)}</p><p>The server currently has ${latest.draft.seen.length} seen and ${(latest.draft.heard || []).length} heard records. Applying your edits keeps unrelated changes saved by another screen.</p><ul>${details.join('') || '<li>No changed sightings or report details.</li>'}</ul><button type="button" class="button button-primary" id="slb-apply-recovery">Apply my edits to latest version</button> <button type="button" class="button" id="slb-discard-recovery">Use latest server version</button>`;
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
            const latest = await request('', {revision:server.revision, seen:sent.seen, heard:sent.heard, title:sent.title, summary:sent.summary});
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
        const heard = new Set(draft.heard);
        const recorded = new Set([...seen,...heard]).size;
        el('preview-content').innerHTML = `<section class="slb-report" aria-label="Bird checklist"><header class="slb-report-header"><p class="slb-report-eyebrow">South Leicester Birdwatchers · Trip bird list</p><h2>${escape(draft.title)}</h2><p class="slb-report-date">${escape(server.eventDate)} / <a href="${escape(server.eventUrl)}">View trip event</a></p></header><div class="slb-report-body">${draft.summary ? `<p class="slb-summary">${escape(draft.summary)}</p>` : ''}<div class="slb-report-totals" aria-label="Trip totals"><p><strong>${seen.size}</strong><span>species seen</span></p><p><strong>${heard.size}</strong><span>species heard</span></p><p aria-label="${recorded} recorded out of ${draft.species.length}"><strong>${recorded}</strong><span>recorded / ${draft.species.length} listed</span></p></div><p class="slb-report-key">Seen and heard are recorded separately; a bird may be both. <span>— = not recorded in that column.</span></p><table><caption>Complete bird checklist</caption><thead><tr><th scope="col">Bird</th><th scope="col">Seen</th><th scope="col">Heard</th></tr></thead><tbody>${draft.species.map(bird => `<tr${seen.has(bird.id) || heard.has(bird.id) ? ' class="slb-recorded"' : ''}><th scope="row">${escape(bird.name)}</th><td>${seen.has(bird.id) ? '<strong>✓ Seen</strong>' : '—'}</td><td>${heard.has(bird.id) ? '<strong>✓ Heard</strong>' : '—'}</td></tr>`).join('')}</tbody></table><p class="slb-report-footer">Complete trip checklist · ${draft.species.length} species</p></div></section>`;
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
        const kind = input.dataset.kind;
        if (!['seen','heard'].includes(kind)) return;
        const selected = new Set(draft[kind]);
        if (input.checked) selected.add(input.value); else selected.delete(input.value);
        draft[kind] = draft.species.filter(b => selected.has(b.id)).map(b => b.id);
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
    function jumpTo(target, focusTarget) {
        const toolbar = root.querySelector('.slb-toolbar');
        const top = parseFloat(getComputedStyle(toolbar).top) || 0;
        target.style.scrollMarginTop = `${toolbar.getBoundingClientRect().height + top + 16}px`;
        focusTarget.focus({preventScroll:true});
        target.scrollIntoView({block:'start'});
    }
    el('jump-bottom').onclick = () => jumpTo(el('report-heading'), el('report-heading'));
    el('jump-top').onclick = () => jumpTo(el('birds'), el('birds').querySelector('.slb-bird:not([hidden]) input:not(:disabled)') || el('search'));
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
            else showReview(server, normalise(pending.baseDraft), normalise(pending.draft), 'This browser has pending edits from an earlier session. Review them against the current server version.');
        }
    } catch {
        storageAvailable = false;
        storageWarning = true;
    }
    if (storageWarning) el('storage').hidden = false;
})();
