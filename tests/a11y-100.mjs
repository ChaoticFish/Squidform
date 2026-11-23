// Accessibility of every SquidForm admin screen in Atum's light mode (axe: serious,
// critical and contrast). The same list runs in dark mode in a11y-dark.mjs.
import { screens } from './a11y-screens.mjs';
await screens(false);
