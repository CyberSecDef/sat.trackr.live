// CSS bundle (Vite extracts this into a stylesheet at build time)
import '../css/main.css';

// Custom element registrations (side-effect imports)
import './ui/ThemeSwitcher';
import './ui/Search';
import './ui/TopBar';
import './globe/Globe';
import './App';

// The pre-paint theme is applied by an inline script in shell.php; nothing
// to do here at startup beyond registering elements.
