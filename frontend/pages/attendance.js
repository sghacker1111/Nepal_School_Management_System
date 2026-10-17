import {registerGroup} from './register-group.js';
registerGroup(['attendance-index'],(root,context)=>root.ownerDocument.defaultView.initAttendanceControls?.(root,context));
