#!/usr/bin/env python3
"""Build and commit incremental gh-pages updates without changing the main checkout."""
import argparse
import os
from pathlib import Path
import subprocess
import sys
import tempfile

ROOT=Path(__file__).resolve().parent.parent
def run(*args,env=None):
    return subprocess.check_output(args,cwd=ROOT,env=env,text=True).strip()
if __name__=='__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--push',action='store_true')
    args=parser.parse_args()
    subprocess.run([sys.executable,str(ROOT/'tools/export_pages.py')],cwd=ROOT,check=True)
    subprocess.run([sys.executable,str(ROOT/'tools/check_pages.py')],cwd=ROOT,check=True)
    current=run('git','rev-parse','HEAD')
    previous_result=subprocess.run(['git','rev-parse','--verify','refs/heads/gh-pages'],cwd=ROOT,text=True,capture_output=True)
    previous=previous_result.stdout.strip() if previous_result.returncode==0 else None
    if previous is None:
        remote_result=subprocess.run(['git','rev-parse','--verify','refs/remotes/origin/gh-pages'],cwd=ROOT,text=True,capture_output=True)
        if remote_result.returncode==0:
            previous=remote_result.stdout.strip()
            run('git','update-ref','refs/heads/gh-pages',previous,'0'*40)
    with tempfile.TemporaryDirectory(prefix='forma-201-index-') as scratch:
        env=dict(os.environ,GIT_INDEX_FILE=str(Path(scratch)/'index'))
        run('git','read-tree','--empty',env=env)
        run('git','--work-tree='+str(ROOT/'.pages-build'),'add','--all','--force',env=env)
        tree=run('git','write-tree',env=env)
        if previous and tree==run('git','rev-parse',previous+'^{tree}'):
            commit=previous
            print('Demo unchanged:',commit)
        else:
            command=['git','commit-tree',tree]
            if previous:command+=['-p',previous]
            command+=['-m','Publish static demo from main '+current[:12]]
            commit=run(*command)
            run('git','update-ref','refs/heads/gh-pages',commit,previous or '0'*40)
            print('Demo commit:',commit)
    if args.push:
        subprocess.run(['git','-c','credential.helper=','-c','credential.helper=!gh auth git-credential','push','origin','gh-pages'],cwd=ROOT,check=True)
