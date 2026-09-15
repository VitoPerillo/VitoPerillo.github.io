export class NullSocialProvider { async validateConnection(){return {ok:true,mode:'disabled'};} async publish(){return {ok:false,skipped:true};} }
export function socialProvider(){return new NullSocialProvider();}
