import { NextRequest, NextResponse } from "next/server";
export const runtime = "nodejs";
const allowed = ["register","login","me","profile","logout","wishlist","orders","order-detail","order-support","privacy-request"];
export async function GET(request: NextRequest, context: { params: Promise<{action:string}> }) { return proxy(request, context, "GET"); }
export async function POST(request: NextRequest, context: { params: Promise<{action:string}> }) { return proxy(request, context, "POST"); }
export async function DELETE(request: NextRequest, context: { params: Promise<{action:string}> }) { return proxy(request, context, "DELETE"); }
async function proxy(request: NextRequest, context: {params:Promise<{action:string}>}, method:string) {
 const {action}=await context.params; if(!allowed.includes(action))return NextResponse.json({message:"Not found."},{status:404});
 const cms=(process.env.WORDPRESS_BASE_URL||"").replace(/\/$/,""); if(!cms)return NextResponse.json({message:"Account service is not configured."},{status:503});
 const token=request.cookies.get("fb_account")?.value; let body:unknown; if(method!=="GET"){try{body=await request.json();}catch{body={};}}
 const query = method === "GET" ? request.nextUrl.search : "";
 try {
  const upstream=await fetch(`${cms}/wp-json/freebooks/v1/account/${action}${query}`,{method,headers:{Accept:"application/json",...(token?{Authorization:`Bearer ${token}`} : {}),...(method!=="GET"?{"Content-Type":"application/json"}:{})},...(method!=="GET"?{body:JSON.stringify(body)}:{}),cache:"no-store",signal:AbortSignal.timeout(15000)});
  const data=await upstream.json().catch(()=>({})); const issuedToken=(action==="login"||action==="register")&&upstream.ok?data?.account?.token:null; if(issuedToken)delete data.account.token; const response=NextResponse.json(data,{status:upstream.status});
  if(issuedToken){response.cookies.set("fb_account",issuedToken,{httpOnly:true,secure:process.env.NODE_ENV==="production",sameSite:"lax",path:"/",maxAge:14*24*60*60});}
  if(action==="logout"){response.cookies.set("fb_account","",{httpOnly:true,secure:process.env.NODE_ENV==="production",sameSite:"lax",path:"/",maxAge:0});}
  return response;
 } catch { return NextResponse.json({message:"Account service could not be reached."},{status:502}); }
}
