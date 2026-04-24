#!/bin/sh

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"

#echo $SCRIPT_DIR

for f in $SCRIPT_DIR/app/code/*; do
    if [ -d "$f" ]; then
    	clear
    	echo "....................Working in $f .........................."
    	cd $f
    	git status
    	STATUS="$(git status)"
    	BRANCH="$(git branch --show-current)"

    	echo $STATUS
    	if [[ "$STATUS" == *"nothing to commit, working tree clean"* ]]; then
		  	git pull origin $BRANCH
	  		#echo -e $RESULT

	  	elif [[ "$STATUS" == *"Changes not staged for commit"* ]]; then
	  		echo "What you need/n"
	  		echo "1 : Commit and pull"
	  		echo "2 : Stash and pull"
	  		echo "enter 1/2"
	  		read input
	  		if [[ "$input" == "1" ]]; then
	  			echo "Enter commit message :"
	  			read message
	  			git add .
	  			git commit -m $message
	  			git pull origin $BRANCH

	  		elif [[ "$input" == "2" ]]; then
	  			git stash
	  			git pull origin $BRANCH

	  		else
	  			echo "Other input so skipping."
	  		fi	
		fi
        # $f is a directory
    fi
done