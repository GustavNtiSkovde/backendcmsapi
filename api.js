const LANGS = ["Svenska", "Engelska"]; // must match values in the `lang` table

const $ = id => document.getElementById(id);
const viewSiteBox = $("ViewSitesBox");
let sitesById = {};

function esc(str) {
    const d = document.createElement("div");
    d.textContent = str ?? "";
    return d.innerHTML;
}

function contentText(content) {
    if (content && typeof content === "object") return content.text ?? "";
    return content ?? "";
}

async function post(payload) {
    const response = await fetch("api.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
    });
    return response.json();
}

// ---------- List ----------
async function loadSites() {
    try {
        const response = await fetch("api.php");
        const result = await response.json();

        if (!result.success) {
            console.error("Error loading sites:", result.message);
            return;
        }

        viewSiteBox.innerHTML = "";
        sitesById = {};

        if (result.data.length === 0) {
            viewSiteBox.innerHTML = "<p>No sites created yet.</p>";
            return;
        }

        result.data.forEach(site => {
            sitesById[site.page_id] = site;

            const langRows = LANGS.map(lang => {
                const t = site.translations.find(x => x.lang === lang);
                return t
                    ? `<p><strong>${esc(lang)}:</strong> ${esc(t.title)}
                         <button class="edit-btn" data-id="${site.page_id}" data-lang="${esc(lang)}">Edit</button></p>`
                    : `<p><strong>${esc(lang)}:</strong> <em>missing</em>
                         <button class="edit-btn" data-id="${site.page_id}" data-lang="${esc(lang)}">Add translation</button></p>`;
            }).join("");

            const card = document.createElement("div");
            card.classList.add("site-card");
            card.innerHTML = `
                <p>Page #${site.page_id} | Created: ${esc(site.created_at)} | Category: ${esc(site.category || "None")}</p>
                ${langRows}
                <button class="remove-btn" data-id="${site.page_id}">Remove Site</button>
                <hr>
            `;
            viewSiteBox.appendChild(card);
        });
    } catch (error) {
        console.error("Fetch error:", error);
    }
}

// ---------- Edit / Remove (event delegation, so no re-attaching) ----------
viewSiteBox.addEventListener("click", async e => {
    const btn = e.target.closest("button");
    if (!btn) return;

    const pageId = btn.dataset.id;

    if (btn.classList.contains("remove-btn")) {
        if (!confirm("Delete this site and all its translations?")) return;
        const result = await post({ action: "DELETE", page_id: pageId });
        alert(result.message);
        if (result.success) loadSites();
        return;
    }

    if (btn.classList.contains("edit-btn")) {
        const lang = btn.dataset.lang;
        const existing = sitesById[pageId].translations.find(t => t.lang === lang);

        const newTitle = prompt(`Title (${lang}):`, existing ? existing.title : "");
        if (newTitle === null || newTitle.trim() === "") return;
        const newContent = prompt(`Content (${lang}):`, existing ? contentText(existing.content) : "");
        if (newContent === null) return;

        const result = await post({
            action: "UPDATE",
            page_id: pageId,
            lang: lang,
            title: newTitle.trim(),
            content: newContent.trim()
        });
        alert(result.message);
        if (result.success) loadSites();
    }
});

// ---------- Create ----------
$("AddSideBtn").addEventListener("click", async e => {
    e.preventDefault();

    const translations = [];
    if ($("SvTitle").value.trim()) {
        translations.push({ lang: "Svenska", title: $("SvTitle").value.trim(), content: $("SvContent").value.trim() });
    }
    if ($("EnTitle").value.trim()) {
        translations.push({ lang: "Engelska", title: $("EnTitle").value.trim(), content: $("EnContent").value.trim() });
    }

    if (!$("CategoryForSite").value || translations.length === 0) {
        alert("Please select a category and fill in a title for at least one language.");
        return;
    }

    try {
        const result = await post({
            action: "CREATE",
            category: $("CategoryForSite").value,
            imgName: $("NameOfImg").value.trim(),
            imgAlt: $("AltTxtForImg").value.trim(),
            translations
        });

        alert(result.message);
        if (result.success) {
            ["SvTitle", "SvContent", "EnTitle", "EnContent", "NameOfImg", "AltTxtForImg", "CategoryForSite"]
                .forEach(id => $(id).value = "");
            loadSites();
        }
    } catch (error) {
        console.error("Fetch error:", error);
    }
});

loadSites();