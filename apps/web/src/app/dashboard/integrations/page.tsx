import { PosSetup } from "@/components/pos-setup";

export default function IntegrationsPage() {
  return <div className="mx-auto max-w-6xl"><p className="eyebrow">POS & integrations</p><h1 className="page-title">Connect your business systems</h1><p className="page-intro">Connect Square Appointments or each Toast restaurant location, then monitor imports without exposing provider credentials to the browser.</p><div className="mt-7"><PosSetup/></div></div>;
}
