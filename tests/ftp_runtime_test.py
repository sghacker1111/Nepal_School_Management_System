"""Read-only verifier uses code/assets only, and catches incomplete transfers."""
import sys,unittest
from pathlib import Path
from unittest.mock import patch
ROOT=Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
import verify_ftp_runtime as subject


class RemoteCode:
    def __init__(self,missing=None,corrupt=None):self.missing=missing;self.corrupt=corrupt;self.calls=[]
    def voidcmd(self,value):assert value=='TYPE I'
    def size(self,name):
        self.calls.append(name)
        return -1 if name==self.missing else (ROOT/name).stat().st_size
    def retrbinary(self,command,callback):
        assert command.startswith('RETR ')
        name=command[5:];self.calls.append(name)
        callback(b'corrupt' if name==self.corrupt else (ROOT/name).read_bytes())


class RuntimeTests(unittest.TestCase):
    def test_public_code_only_and_complete(self):
        paths=subject.runtime_paths();remote=RemoteCode()
        self.assertEqual(subject.verify(remote,paths),(len(paths),12))
        self.assertTrue(any('/plugins/code/' in path for path in paths))
        self.assertTrue(any('/fonts/woff-v2/' in path for path in paths))
        self.assertFalse(any(path.startswith(('uploads/','tests/','scratch/','frontend/')) or '.local.php' in path or '.sqlite' in path or path.endswith('.sql') for path in remote.calls))
    def test_missing_font_stops_release(self):
        paths=subject.runtime_paths();font=next(path for path in paths if path.endswith('.woff'))
        with self.assertRaisesRegex(ValueError,'size differs'):subject.verify(RemoteCode(missing=font),paths)
    def test_corrupt_bundle_stops_release(self):
        with self.assertRaisesRegex(ValueError,'content differs'):subject.verify(RemoteCode(corrupt='assets/dist/workspace.js'),subject.runtime_paths())
    def test_unsafe_destination_refused_before_connection(self):
        for folder in ('/','../htdocs/','https://school.example/htdocs/','htdocs/../'):
            with patch.dict(subject.os.environ,{'FTP_SERVER':'example.test','FTP_USERNAME':'synthetic','FTP_PASSWORD':'synthetic','FTP_SERVER_DIR':folder}),patch.object(subject.ftplib,'FTP_TLS') as connect:
                with self.assertRaisesRegex(ValueError,'validated htdocs'):subject.main()
                connect.assert_not_called()


if __name__=='__main__':unittest.main()
