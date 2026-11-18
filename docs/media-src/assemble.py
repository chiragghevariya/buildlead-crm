import json, subprocess, sys, os
FR, OUT = sys.argv[1], sys.argv[2]
d = json.load(open(f'{FR}/frames.json')); fr = d['frames']; m = d['markers']
t0 = fr[0]['ts']
with open(f'{FR}/list.txt', 'w') as f:
    for a, b in zip(fr, fr[1:]):
        f.write(f"file '{os.path.abspath(os.path.join(FR, os.path.basename(a['file'])))}'\nduration {max(b['ts'] - a['ts'], 0.001):.4f}\n")
    f.write(f"file '{os.path.abspath(os.path.join(FR, os.path.basename(fr[-1]['file'])))}'\nduration 1.5\nfile '{os.path.abspath(os.path.join(FR, os.path.basename(fr[-1]['file'])))}'\n")
mp4 = f'{OUT}/video/buildlead-demo.mp4'
subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', '-f', 'concat', '-safe', '0', '-i', f'{FR}/list.txt',
  '-vf', 'fps=30,scale=1920:1080:flags=lanczos,format=yuv420p', '-c:v', 'libx264', '-preset', 'slow', '-crf', '18',
  '-profile:v', 'high', '-level', '4.1', '-movflags', '+faststart', '-metadata', 'title=BuildLead CRM demo', mp4], check=True)
# GIF: 15 s around the hero moment (create lead → scored Hot → AI breakdown)
start = m['submit'] - t0 - 3.0
gif = f'{OUT}/video/buildlead-demo.gif'
vf = 'fps=12,scale=960:-1:flags=lanczos'
subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', '-ss', f'{start:.2f}', '-t', '15', '-i', mp4, '-vf', f'{vf},palettegen=max_colors=192:stats_mode=diff', f'{FR}/pal.png'], check=True)
subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', '-ss', f'{start:.2f}', '-t', '15', '-i', mp4, '-i', f'{FR}/pal.png',
  '-lavfi', f'{vf}[x];[x][1:v]paletteuse=dither=bayer:bayer_scale=4:diff_mode=rectangle', gif], check=True)
# Poster frame for players / README
subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', '-ss', f"{m['scored'] - t0 + 0.6:.2f}", '-i', mp4, '-frames:v', '1', f'{OUT}/video/buildlead-demo-poster.jpg'], check=True)
print('gif window', round(start, 1), '→', round(start + 15, 1))
