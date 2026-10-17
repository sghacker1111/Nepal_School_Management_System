import json,os,subprocess,tempfile,unittest,uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
class ServerTests(unittest.TestCase):
    def test_rejects_application_directory(self):
        helper=ROOT/'tools/mysql_test_server.ps1'
        self.assertTrue(helper.is_file(),'Owned local MySQL server helper is missing')
        result=subprocess.run(['powershell','-NoProfile','-File',str(helper),'-Action','Start','-StateDirectory',str(ROOT/'unsafe-mysql-server')],capture_output=True,text=True)
        self.assertNotEqual(result.returncode,0);self.assertFalse((ROOT/'unsafe-mysql-server').exists())
    def test_start_stop_only_its_owned_instance(self):
        helper=ROOT/'tools/mysql_test_server.ps1'
        self.assertTrue(helper.is_file(),'Owned local MySQL server helper is missing')
        directory=Path(tempfile.gettempdir())/('erp-mysql-server-'+uuid.uuid4().hex)
        result=subprocess.run(['powershell','-NoProfile','-File',str(helper),'-Action','Start','-StateDirectory',str(directory)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=60)
        self.assertEqual(result.returncode,0,'Temporary MySQL did not start; private diagnostics retained at '+str(directory))
        state=json.loads((directory/'state.json').read_text(encoding='utf-8-sig'))
        self.assertEqual(state['host'],'127.0.0.1');self.assertNotEqual(state['port'],3306)
        self.assertNotIn(state['password'],(directory/'server.err').read_text(encoding='utf-8',errors='replace'))
        stop=subprocess.run(['powershell','-NoProfile','-File',str(helper),'-Action','Stop','-StateDirectory',str(directory)],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=30)
        self.assertEqual(stop.returncode,0,'Owned server did not stop safely')

if __name__=='__main__':unittest.main()
