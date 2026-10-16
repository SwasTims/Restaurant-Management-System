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

// ---- Edit popup: copy the clicked row's data into the form, then open it ----
const editDialog = document.getElementById('editDialog');

if (editDialog) {
    document.querySelectorAll('[data-edit]').forEach(function (button) {
        button.addEventListener('click', function () {
            editDialog.querySelector('[name="id"]').value = button.dataset.id;
            editDialog.querySelector('[name="name"]').value = button.dataset.name;
            editDialog.querySelector('[name="email"]').value = button.dataset.email;
            editDialog.querySelector('[name="role"]').value = button.dataset.role;
            editDialog.querySelector('[name="status"]').value = button.dataset.status;
            editDialog.querySelector('[name="password"]').value = '';
            editDialog.showModal();
        });
    });
}

// ---- Delete popup: show the clicked person's name, remember their id ----
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

// ---- Image preview: show the chosen file before it is uploaded ----
document.querySelectorAll('input[type="file"][data-preview]').forEach(function (input) {
    const img = document.getElementById(input.dataset.preview);
    const emptyText = img.nextElementSibling;

    input.addEventListener('change', function () {
        // Free the memory used by the previous preview
        if (img.dataset.url) {
            URL.revokeObjectURL(img.dataset.url);
            delete img.dataset.url;
        }

        let file = input.files[0];

        // Browser-side size check (the server checks again)
        if (file && file.size > 2 * 1024 * 1024) {
            alert('Image must be 2 MB or smaller.');
            input.value = '';
            file = null;
        }

        if (file) {
            const url = URL.createObjectURL(file);
            img.src = url;
            img.dataset.url = url;
            img.hidden = false;
            emptyText.hidden = true;
        } else {
            img.removeAttribute('src');
            img.hidden = true;
            emptyText.hidden = false;
        }
    });
});