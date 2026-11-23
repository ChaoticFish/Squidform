// Accessibility of every SquidForm admin screen in Atum's dark mode (axe: serious,
// critical and contrast). The same list runs in light mode in a11y-100.mjs.
import { screens } from './a11y-screens.mjs';
await screens(true);
