let addTitleToSite = document.getElementById("TitleForSite");
let addContentForSite = document.getElementById("ContentForSite");
let nameOfImg = document.getElementById("NameOfImg");
let altTxtForImg = document.getElementById("AltTxtForImg");
let categoryForSite = document.getElementById("CategoryForSite");
let langForSite = document.getElementById("LangForSite");
let addSideBtn = document.getElementById("AddSideBtn");
let viewSiteBox = document.getElementById("ViewSitesBox");

// 1. Function to fetch and display sites in HTML
async function loadSites() {
    try {
        const response = await fetch("api.php");
        const result = await response.json();

        if (result.success) {
            viewSiteBox.innerHTML = ""; // Clear existing elements

            if (result.data.length === 0) {
                viewSiteBox.innerHTML = "<p>No sites created yet.</p>";
                return;
            }

            result.data.forEach(site => {
                const siteCard = document.createElement("div");
                siteCard.classList.add("site-card");

                siteCard.innerHTML = `
                    <p>${site.title} Created: ${site.created_at} Language:${site.lang || 'None'} Category: ${site.category || 'None'}</p>
                    <button class="edit-btn" data-id="${site.page_id}" data-title="${site.title}">Edit Site</button>
                    <button class="remove-btn" data-id="${site.page_id}">Remove Site</button>
                    <hr>
                `;

                viewSiteBox.appendChild(siteCard);
            });

            // Attach event listeners for edit and remove buttons
            attachActionListeners();
        } else {
            console.error("Error loading sites:", result.message);
        }
    } catch (error) {
        console.error("Fetch error:", error);
    }
}

// 2. Attach Click Handlers for Remove & Edit Buttons
function attachActionListeners() {
    // 1. Remove Buttons Logic
    const removeButtons = document.querySelectorAll(".remove-btn");
    removeButtons.forEach(button => {
        button.addEventListener("click", async function () {
            const pageId = this.getAttribute("data-id");

            if (confirm("Are you sure you want to delete this site?")) {
                await removeSite(pageId);
            }
        });
    });

    // 2. Edit Buttons Logic
    const editButtons = document.querySelectorAll(".edit-btn");
    editButtons.forEach(button => { // <-- 'button' is defined here as the array item
        button.addEventListener("click", async function () {
            const pageId = this.getAttribute("data-id");
            const currentTitle = this.getAttribute("data-title") || "";
            const currentContent = this.getAttribute("data-content") || "";
            const currentLanguage = this.getAttribute("data-language") || "";

            const newTitle = prompt("Enter new title:", currentTitle);
            const newContent = prompt("Enter new content:", currentContent);
            const newLanguage = prompt("Enter new language:", currentLanguage);

            // Build payload object
            const updateData = {
                action: "UPDATE",
                page_id: pageId
            };

            let hasChanges = false;

            if (newTitle !== null && newTitle.trim() !== "") {
                updateData.title = newTitle.trim();
                hasChanges = true;
            }
            if (newContent !== null && newContent.trim() !== "") {
                updateData.content = newContent.trim();
                hasChanges = true;
            }
            if (newLanguage !== null && newLanguage.trim() !== "") {
                updateData.language = newLanguage.trim();
                hasChanges = true;
            }

            // Make update if at least 1 field was provided
            if (hasChanges) {
                await updateSite(updateData);
            }
        });
    });
}

// Api call to Delete site
async function removeSite(pageId) {
    try {
        const response = await fetch("api.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ 
                action: "DELETE", 
                page_id: pageId 
            })
        });

        const result = await response.json();
        if (result.success) {
            alert("Site deleted successfully!");
            loadSites();
        } else {
            alert("Error: " + result.message);
        }
    } catch (error) {
        console.error("Remove error:", error);
    }
}

// Api call to Edit site
async function updateSite(updateData) {
    try {
        const response = await fetch("api.php", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(updateData)
        });

        const result = await response.json();
        if (result.success) {
            alert(result.message);
            loadSites();
        } else {
            alert("Error updating: " + result.message);
        }
    } catch (error) {
        console.error("Update error:", error);
    }
}

// 5. Add Event Listener for Form Submission
addSideBtn.addEventListener("click", async function (e) {
    e.preventDefault();

    if (!categoryForSite.value || !langForSite.value) {
        alert("Please select both a category and a language.");
        return;
    }

    const siteData = {
        title: addTitleToSite.value,
        content: addContentForSite.value,
        imgName: nameOfImg.value,
        imgAlt: altTxtForImg.value,
        category: categoryForSite.value,
        lang: langForSite.value
    };

    try {
        const response = await fetch("api.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify(siteData)
        });

        const result = await response.json();

        if (result.success) {

            // Reset inputs
            addTitleToSite.value = "";
            addContentForSite.value = "";
            nameOfImg.value = "";
            altTxtForImg.value = "";
            categoryForSite.value = "";
            langForSite.value = "";

            loadSites();
        } else {
            alert("Error: " + result.message);
        }
    } catch (error) {
        console.error("Fetch error:", error);
    }
});

// 6. Load existing sites on page load
loadSites();