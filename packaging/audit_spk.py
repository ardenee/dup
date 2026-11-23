#!/usr/bin/env python3
import tarfile, io, json, sys, re
from pathlib import Path
for p in sys.argv[1:]:
 p=Path(p)
 print("\nFILE",p.name,p.stat().st_size)
 with tarfile.open(p,"r:") as t:
  members=t.getmembers()
  print("format first header",p.read_bytes()[257:265])
  print("outer",[(x.name,x.size,oct(x.mode),x.type.decode('ascii','replace')) for x in members])
  info=t.extractfile("INFO").read().decode("utf-8")
  print("INFO:\n",info)
  z=t.extractfile("package.tgz").read()
 with tarfile.open(fileobj=io.BytesIO(z),mode="r:gz") as inner:
  ins=inner.getmembers()
  print("inner first 12",[(x.name,x.type.decode("ascii","replace")) for x in ins[:12]])
  print("inner files count",len(ins))
  print("ui files", [x.name for x in ins if '/ui/' in x.name and x.isfile()][:25])
 print("OUTER SIGNATURE", repr(p.read_bytes()[:16]))
