import { NextRequest, NextResponse } from "next/server";
export const runtime = "nodejs";
export async function GET(request: NextRequest, context: { params: Promise<{ reference: string }> }) {
 const cms=(process.env.WORDPRESS_BASE_URL||"").replace(/\/$/,"");if(!cms)return NextResponse.json({message:"Donation tracking is not configured."},{status:503});
 const {reference}=await context.params;const token=request.headers.get("x-freebooks-portal-token")||"";
 try{const response=await fetch(`${cms}/wp-json/freebooks/v1/donations/portal/${encodeURIComponent(reference)}`,{headers:{Accept:"application/json","X-FreeBooks-Portal-Token":token},cache:"no-store",signal:AbortSignal.timeout(15000)});const data=await response.json().catch(()=>({}));return NextResponse.json(data,{status:response.status});}
 catch{return NextResponse.json({message:"The tracking service could not be reached."},{status:502});}
}
