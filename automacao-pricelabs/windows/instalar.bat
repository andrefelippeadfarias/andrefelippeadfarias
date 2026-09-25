@cd /d "%~dp0.." && py -3 -m pacing configurar-chaves && py -3 -m pacing agendar && py -3 -m pacing executar & py -3 -m pacing verificar & pause
