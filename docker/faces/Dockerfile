# Service de reconnaissance de visages (InsightFace, modèles buffalo_l) :
# détection SCRFD, empreinte ArcFace 512, âge estimé. Interne au réseau
# compose (aucun port publié), CPU seul. Les photos ne quittent pas le serveur.
# ⚠️ Modèles pré-entraînés InsightFace : usage non commercial (cf. README).
FROM docker.io/library/python:3.12.11-slim-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends libgl1 libglib2.0-0 \
 && rm -rf /var/lib/apt/lists/*

# Versions figées : publiées avant la date d'exclusion (au moins deux semaines).
RUN pip install --no-cache-dir uv==0.8.17 \
 && uv pip install --system --no-cache --exclude-newer 2026-09-27T00:00:00Z \
      insightface==2.0 opencv-python-headless numpy pillow

# Modèles téléchargés à la construction : le service démarre sans Internet.
ENV INSIGHTFACE_ROOT=/models
RUN python -c "from insightface.app import FaceAnalysis; FaceAnalysis(name='buffalo_l', root='/models', allowed_modules=['detection','recognition','genderage'])" \
 && rm -f /models/models/*.zip

COPY server.py /app/server.py
WORKDIR /app
EXPOSE 8000
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=4)"
CMD ["python", "-u", "server.py"]
