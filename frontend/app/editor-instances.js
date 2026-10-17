// TinyMCE 6 exposes its registry through get(), not a public `editors` array.
export function editorInstances(win) {
    const editors=win.tinymce?.get?.();
    return Array.isArray(editors)?editors:(Array.isArray(win.tinymce?.editors)?win.tinymce.editors:[]);
}
