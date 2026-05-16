// CSS bundle (Vite extracts this into a stylesheet at build time)
import '../css/main.css';

// Custom element registrations (side-effect imports)
import './ui/FreshnessBadge';
import './ui/ThemeSwitcher';
import './ui/Search';
import './ui/ObserverPill';
import './ui/OverlaysMenu';
import './ui/SpaceWeatherPill';
import './ui/StationTooltip';
import './ui/TopBar';
import './ui/DetailPanel';
import './ui/Timeline';
import './ui/NoWebGL';
import './globe/Globe';
import './App';

// The pre-paint theme is applied by an inline script in shell.php; nothing
// to do here at startup beyond registering elements.
