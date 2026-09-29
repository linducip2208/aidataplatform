import './bootstrap';

// Tabler interactive components used by the shell (sidebar collapse on
// mobile, user-menu dropdown). Alpine stays for the inline directives in the
// dataset wizard (delete confirmation).
import '@tabler/core/dist/js/tabler.min.js';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();
