# Vánoční poukaz z oranžového dárkového návrhu salonu: nápis VÁNOČNÍ, kuličky, vločky, cesmína. Spuštění: python3 tools/poukaz-vanocni-oranzovy.py
from PIL import Image, ImageDraw, ImageFont, ImageFilter
import random, math
import sys; src=sys.argv[1] if len(sys.argv)>1 else 'assets/img/poukaz-pozadi.jpg'
im=Image.open(src).convert('RGB'); W,H=im.size
# 1) titulek: DÁRKOVÝ -> VÁNOČNÍ
d=ImageDraw.Draw(im)
beige=im.getpixel((40,30))
d.rectangle((60,30,790,190), fill=beige)
F='/usr/share/fonts/truetype/liberation/LiberationSerif-Bold.ttf'
brown=(167,64,22); shadow=(70,35,22)
txt='VÁNOČNÍ'
# velikost tak, aby šířka ~ 700 px a výška velkých písmen jako původní (~130 px)
size=180
f=ImageFont.truetype(F,size)
bb=d.textbbox((0,0),txt,font=f,anchor='ls')
w=bb[2]-bb[0]
if w>700: size=int(size*700/w); f=ImageFont.truetype(F,size); bb=d.textbbox((0,0),txt,font=f,anchor='ls'); w=bb[2]-bb[0]
x=75; base=172
d.text((x+4,base+4),txt,font=f,fill=shadow,anchor='ls')
d.text((x,base),txt,font=f,fill=brown,anchor='ls')
print('title size',size,'w',w)
# 2) ozdoby ve vrstvě 2x a zmenšení (hladké hrany)
S=2
L=Image.new('RGBA',(W*S,H*S),(0,0,0,0)); g=ImageDraw.Draw(L)
def bauble(cx,top,length,r,col,hi):
    cx*=S; top*=S; length*=S; r*=S
    g.line((cx,top,cx,top+length),fill=(200,160,90,255),width=2*S)
    cy=top+length+r
    g.rectangle((cx-r*0.28,top+length-r*0.15,cx+r*0.28,top+length+r*0.25),fill=(214,176,98,255))
    g.ellipse((cx-r,cy-r,cx+r,cy+r),fill=col+(255,))
    g.ellipse((cx-r*0.55,cy-r*0.6,cx-r*0.05,cy-r*0.15),fill=hi+(150,))
# kuličky v pravém horním rohu fotky
for cx,ln,r,col in [(1500,150,30,(176,24,32)),(1590,60,36,(214,170,70)),(1675,120,28,(176,24,32)),(1752,40,32,(35,110,55))]:
    bauble(cx,0,ln,r,col,(255,255,255))
def flake(cx,cy,r,a=220,w=3):
    cx*=S; cy*=S; r*=S
    for k in range(6):
        ang=math.pi/3*k; x2=cx+r*math.cos(ang); y2=cy+r*math.sin(ang)
        g.line((cx,cy,x2,y2),fill=(255,255,255,a),width=w*S)
        for t in (0.55,):
            bx=cx+r*t*math.cos(ang); by=cy+r*t*math.sin(ang)
            for s in (-1,1):
                a2=ang+s*math.pi/4
                g.line((bx,by,bx+r*0.32*math.cos(a2),by+r*0.32*math.sin(a2)),fill=(255,255,255,a),width=w*S)
# vločky přes fotku (mimo nápis POUKAZ a mimo ženu vpravo dole)
random.seed(7)
for (cx,cy,r) in [(870,260,26),(1010,420,18),(1180,250,22),(1300,470,30),(1450,330,20),(1620,300,26),(1720,420,16),(940,500,14),(1560,505,14),(1110,515,12)]:
    flake(cx,cy,r,200,2 if r<20 else 3)
for _ in range(70):
    x=random.randint(820,1780); y=random.randint(200,530)
    if x>1580 and y>480: continue
    rr=random.uniform(2,5)*S
    g.ellipse((x*S-rr,y*S-rr,x*S+rr,y*S+rr),fill=(255,255,255,random.randint(120,210)))
# větvička cesmíny vpravo od „MOST“
def leaf(cx,cy,l,wd,ang,col=(32,110,52)):
    pts=[]
    for i in range(0,41):
        t=i/40; x=-l/2+l*t
        yy=wd/2*math.sin(math.pi*t)*(1+0.18*math.sin(6*math.pi*t))
        pts.append((x,yy))
    pts=pts+[(x,-y) for x,y in reversed(pts)]
    ca,sa=math.cos(ang),math.sin(ang)
    P=[((cx+x*ca-y*sa)*S,(cy+x*sa+y*ca)*S) for x,y in pts]
    g.polygon(P,fill=col+(255,))
    g.line(((cx-l/2*ca)*S,(cy-l/2*sa)*S,(cx+l/2*ca)*S,(cy+l/2*sa)*S),fill=(20,75,35,255),width=2*S)
hx,hy=690,262
leaf(hx-46,hy-4,82,36,math.radians(-20))
leaf(hx+46,hy-4,82,36,math.radians(20),(40,125,60))
leaf(hx+2,hy-30,60,30,math.radians(-80),(28,100,48))
for dx,dy in [(-14,4),(14,4),(0,-14)]:
    r=14*S; cx=(hx+dx)*S; cy=(hy+dy)*S
    g.ellipse((cx-r,cy-r,cx+r,cy+r),fill=(185,22,32,255))
    g.ellipse((cx-r*0.5,cy-r*0.6,cx-r*0.05,cy-r*0.15),fill=(255,255,255,140))
L=L.resize((W,H),Image.LANCZOS)
out=im.convert('RGBA'); out.alpha_composite(L); out=out.convert('RGB')
out.save('assets/img/poukaz-pozadi-vanocni.jpg',quality=92)

