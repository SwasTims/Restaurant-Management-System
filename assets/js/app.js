const shell = document.querySelector('.app-shell');
const toggle = document.getElementById('menuToggle');

// ---- Sidebar collapse ----
toggle.addEventListener('click', function () {
    shell.classList.toggle('sidebar-collapsed');
});

// ---- Popups (dialogs) ----

// Any button with data-open-dialog="someId" opens that dialog
document.querySelectorAll('[data-open-dialog]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById(button.dataset.openDialog).showModal();
    });
});

// Any button with data-close-dialog closes the dialog it sits inside
document.querySelectorAll('[data-close-dialog]').forEach(function (button) {
    button.addEventListener('click', function () {
        button.closest('dialog').close();
    });
});

// Clicking the dark area outside the popup closes it
document.querySelectorAll('dialog').forEach(function (dialog) {
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            dialog.close();
        }
    });
});

// A dialog marked data-auto-open opens as soon as the page loads (used after errors)
document.querySelectorAll('dialog[data-auto-open]').forEach(function (dialog) {
    dialog.showModal();
});

// ---- Photo preview helpers ----

// Show a picture in a preview box (url), or show the "no photo" text (empty url)
function setPreview(img, url) {
    const emptyText = img.nextElementSibling;

    if (img.dataset.url) {
        URL.revokeObjectURL(img.dataset.url); // free the memory of a previous local preview
        delete img.dataset.url;
    }

    if (url) {
        img.src = url;
        img.hidden = false;
        emptyText.hidden = true;
    } else {
        img.removeAttribute('src');
        img.hidden = true;
        emptyText.hidden = false;
    }
}

// When a file is chosen, preview it before it is uploaded
document.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
    const img = document.getElementById(input.dataset.preview);

    input.addEventListener('change', function () {
        let file = input.files[0];

        // Browser-side size check (the server checks again)
        if (file && file.size > 2 * 1024 * 1024) {
            alert('Image must be 2 MB or smaller.');
            input.value = '';
            file = null;
        }

        if (file) {
            const url = URL.createObjectURL(file);
            setPreview(img, url);
            img.dataset.url = url;
        } else {
            // No new file: go back to the item's current photo (if it has one)
            setPreview(img, img.dataset.original || '');
        }
    });
});

// ---- Edit popup: copy the clicked row's data into the form, then open it ----
// Every data-xxx attribute on the button fills the field named "xxx" in the popup.
const editDialog = document.getElementById('editDialog');

if (editDialog) {
    document.querySelectorAll('[data-edit]').forEach(function (button) {
        button.addEventListener('click', function () {
            Object.keys(button.dataset).forEach(function (key) {
                if (key === 'edit' || key === 'previewUrl') {
                    return;
                }
                const field = editDialog.querySelector('[name="' + key + '"]');
                if (field) {
                    field.value = button.dataset[key];
                }
            });

            // Passwords and chosen files never carry over from a previous edit
            editDialog.querySelectorAll('input[type="password"], input[type="file"]').forEach(function (field) {
                field.value = '';
            });

            // Photo preview: show this item's current photo, if there is one
            const img = editDialog.querySelector('.preview-box img');
            if (img) {
                const current = button.dataset.previewUrl || '';
                img.dataset.original = current;
                setPreview(img, current);
            }

            editDialog.showModal();
        });
    });
}

// ---- Delete popup: show the clicked row's name, remember its id ----
const deleteDialog = document.getElementById('deleteDialog');

if (deleteDialog) {
    document.querySelectorAll('[data-delete]').forEach(function (button) {
        button.addEventListener('click', function () {
            deleteDialog.querySelector('[name="id"]').value = button.dataset.id;
            deleteDialog.querySelector('#deleteName').textContent = button.dataset.name;
            deleteDialog.showModal();
        });
    });
}

// ---- Restock popup: show the ingredient's name and unit, remember its id ----
const restockDialog = document.getElementById('restockDialog');

if (restockDialog) {
    document.querySelectorAll('[data-restock]').forEach(function (button) {
        button.addEventListener('click', function () {
            restockDialog.querySelector('[name="id"]').value = button.dataset.id;
            restockDialog.querySelector('[name="amount"]').value = '';
            restockDialog.querySelector('#restockName').textContent = button.dataset.name;
            restockDialog.querySelector('#restockUnit').textContent = button.dataset.unit;
            restockDialog.showModal();
        });
    });
}