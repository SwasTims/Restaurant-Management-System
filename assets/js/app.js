const shell = document.querySelector('.app-shell');
const toggle = document.getElementById('menuToggle');

toggle.addEventListener('click', function () {
    shell.classList.toggle('sidebar-collapsed');
});