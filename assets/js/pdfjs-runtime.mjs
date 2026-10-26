import * as pdfjs from '../vendor/pdfjs-6.4.299/pdf.min.mjs';
pdfjs.GlobalWorkerOptions.workerSrc = new URL('../vendor/pdfjs-6.4.299/pdf.worker.min.mjs', import.meta.url).href;
window.pdfjsLib = pdfjs;
