import fs from "node:fs";
const project=process.argv[2];
const profile=process.argv[3]||"default";
if(!project){console.error("COMPLIANCE_BLOCKED missing_project");process.exit(2);}
const path=new URL("./"+project+".json",import.meta.url);
if(!fs.existsSync(path)){console.error("COMPLIANCE_BLOCKED missing_manifest "+project);process.exit(2);}
const m=JSON.parse(fs.readFileSync(path,"utf8"));
if(m.project_id!==project){console.error("COMPLIANCE_BLOCKED project_id_mismatch");process.exit(2);}
const status=m.release_profiles?.[profile]?.status||m.current_status;
if(status!=="PASS"){
  console.error(JSON.stringify({status:"COMPLIANCE_BLOCKED",project,profile,current_status:status||"missing",blocker:m.release_profiles?.[profile]?.blocker||m.blocker||"central_gate_not_passed"}));
  process.exit(1);
}
if(!m.evidence||m.evidence.length===0){console.error("COMPLIANCE_BLOCKED no_evidence");process.exit(1);}
console.log(JSON.stringify({status:"PASS",project,profile,evidence:m.evidence}));
