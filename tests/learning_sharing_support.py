import json,subprocess

def extend_learning(app):
    result=subprocess.run([app.php,'tests/learning_sharing_fixture.php',json.dumps(app.fixture)],cwd=app.root,env=app.env,capture_output=True,text=True,check=True)
    app.fixture=json.loads(result.stdout)
    return app.fixture
