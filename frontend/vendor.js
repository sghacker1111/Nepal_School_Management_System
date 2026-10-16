// Compatibility plugins are isolated from the new Alpine/HTMX application.
// They remain necessary for existing sortable/exportable school ledgers.
import jQuery from 'jquery';
import dataTables from 'datatables.net-bs5';
import Swal from 'sweetalert2';
import 'sweetalert2/dist/sweetalert2.min.css';
window.$ = window.jQuery = jQuery;
dataTables(window, jQuery);
window.Swal = Swal;
