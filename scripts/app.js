// Application entry as an ES module. The framework lives in scripts/arix/
// and has no side effects on import: only what is called below runs.
import { enhance } from './arix/index.js';

// Opt-in progressive enhancement. Only elements marked with data-arix-get or
// data-arix-post are handled; links and forms without those attributes keep
// their native behavior. Web Components from the framework are optional and
// are registered per component with define(), never automatically.
enhance(document);
