import { NextRequest, NextResponse } from "next/server";
export const runtime = "nodejs";
export async function POST(request: NextRequest, context: { params: Promise<{ action: string }> }) {
  const cms=(process.env.WORDPRESS_BASE_URL||"").replace(/\/$/,"");
  if(!cms)return NextResponse.json({message:"Donation tracking is not configured yet."},{status:503});
  const {action}=await context.params;
  if(!["request-otp","verify-otp"].includes(action))return NextResponse.json({message:"Not found."},{status:404});
  let body: unknown;try{body=await request.json();}catch{return NextResponse.json({message:"Please check the details and try again."},{status:400});}
  try{const response=await fetch(`${cms}/wp-json/freebooks/v1/donations/portal/${action}`,{method:"POST",headers:{"Content-Type":"application/json",Accept:"application/json"},body:JSON.stringify(body),cache:"no-store",signal:AbortSignal.timeout(15000)});const data=await response.json().catch(()=>({}));return NextResponse.json(data,{status:response.status});}
  catch{return NextResponse.json({message:"The tracking service could not be reached. Please try again shortly."},{status:502});}
}
